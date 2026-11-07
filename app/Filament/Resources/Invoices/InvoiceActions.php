<?php

namespace App\Filament\Resources\Invoices;

use App\Billing\Billing;
use App\Billing\BillingConfig;
use App\Billing\BillingException;
use App\Billing\InvoicePdf;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Users\UserResource;
use App\Models\Invoice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actions on invoices, shared by the list and the detail page. Invoices are never deleted
 * (10 years retention): wrong ones are cancelled.
 */
class InvoiceActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [self::pdf(), self::markPaid(), self::resend(), self::user(), self::cancel()];
    }

    public static function pdf(): Action
    {
        return Action::make('pdf')
            ->label(__('PDF'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (Invoice $record): StreamedResponse => app(InvoicePdf::class)->download($record));
    }

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->schema([
                DatePicker::make('paid_at')->label(__('Paid on'))->default(now())->maxDate(now())->required(),
                Textarea::make('note')->label(__('Note'))->helperText(__('e.g. bank, cash or a different amount.'))->maxLength(1000),
            ])
            ->visible(fn (Invoice $record): bool => $record->canBeMarkedPaid())
            ->action(function (Invoice $record, array $data): void {
                $record->markPaid(Carbon::parse($data['paid_at']), $data['note'] ?? null, self::admin());
                Notification::make()->title(__('Invoice :number is marked as paid.', ['number' => $record->number]))->success()->send();
            });
    }

    public static function resend(): Action
    {
        return Action::make('resend')
            ->label(__('Send again'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record): string => __('The invoice goes to :email again, with the PDF attached.', ['email' => $record->user?->email]))
            ->visible(fn (Invoice $record): bool => $record->user_id !== null && in_array($record->status, [InvoiceStatus::Open, InvoiceStatus::Overdue, InvoiceStatus::Paid], true))
            ->action(function (Invoice $record): void {
                app(Billing::class)->send($record);
                Notification::make()->title(__('Invoice :number is sent again.', ['number' => $record->number]))->success()->send();
            });
    }

    /**
     * Suspending stays manual (KONZEPT 5.4): the user page has the suspend actions of the plans
     * and accounts.
     */
    public static function user(): Action
    {
        return Action::make('user')
            ->label(fn (Invoice $record): string => $record->status === InvoiceStatus::Overdue ? __('Suspend plan or access') : __('Open user'))
            ->icon(fn (Invoice $record): string => $record->status === InvoiceStatus::Overdue ? 'heroicon-o-no-symbol' : 'heroicon-o-user')
            ->color(fn (Invoice $record): string => $record->status === InvoiceStatus::Overdue ? 'danger' : 'gray')
            ->visible(fn (Invoice $record): bool => $record->user_id !== null)
            ->url(fn (Invoice $record): string => UserResource::getUrl('edit', ['record' => $record->user_id]));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel invoice'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(fn (Invoice $record): string => __('Cancel invoice :number', ['number' => $record->number]))
            ->modalDescription(__('The invoice is kept (10 years) but no longer payable; the user is not notified. Its periods are not billed again.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->maxLength(1000),
            ])
            ->visible(fn (Invoice $record): bool => $record->canBeCancelled())
            ->action(function (Invoice $record, array $data): void {
                $record->cancel((string) $data['reason'], self::admin());
                Notification::make()->title(__('Invoice :number is cancelled.', ['number' => $record->number]))->success()->send();
            });
    }

    /**
     * The invoice run by hand (the same as the daily `billing:run`).
     */
    public static function run(): Action
    {
        return Action::make('run')
            ->label(__('Run billing'))
            ->icon('heroicon-o-play')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => app(BillingConfig::class)->enabled()
                ? __('Creates and sends the invoices of all due periods, marks overdue invoices and sends the reminders (also runs daily).')
                : __('Billing is off (BILLING_CREDITOR_IBAN is not set): only overdue invoices are marked and reminded.'))
            ->action(function (): void {
                try {
                    $run = app(Billing::class)->run();
                } catch (BillingException $exception) {
                    Notification::make()->title(__('Billing failed'))->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Billing done'))->body($run->summary())->success()->send();
            });
    }

    private static function admin(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}

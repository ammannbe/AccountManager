<?php

namespace App\Filament\Resources\Invoices;

use App\Billing\Money;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoices of the subscriptions (created by the invoice run, never by hand). Payments are marked by
 * hand; a camt.054 import follows later (KONZEPT 5.4).
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('Invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invoices');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Invoice::query()->where('status', InvoiceStatus::Overdue)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $date = fn (mixed $value): ?string => $value?->format('d.m.Y');

        return $schema->components([
            Section::make(__('Invoice'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('number')->label(__('Number'))->copyable(),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('total_cents')->label(__('Total'))->state(fn (Invoice $record): string => Money::format($record->total_cents, $record->currency)),
                    TextEntry::make('issued_at')->label(__('Invoice date'))->date('d.m.Y'),
                    TextEntry::make('due_at')->label(__('Payable by'))->date('d.m.Y'),
                    TextEntry::make('period')
                        ->label(__('Period'))
                        ->state(fn (Invoice $record): ?string => $record->period_start ? trim($date($record->period_start).' – '.$date($record->period_end), ' –') : null)
                        ->placeholder('–'),
                    TextEntry::make('reference')->label(__('Reference'))->state(fn (Invoice $record): string => $record->reference ?? $record->reference_type->value)->fontFamily('mono')->copyable(),
                    TextEntry::make('sent_at')->label(__('Sent'))->dateTime()->placeholder('–'),
                    TextEntry::make('reminded_at')->label(__('Reminder sent'))->dateTime()->placeholder('–'),
                ]),
            Section::make(__('Recipient'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('recipient.name')->label(__('Name')),
                    TextEntry::make('recipient.email')->label(__('Email'))->placeholder('–'),
                    TextEntry::make('address')
                        ->label(__('Address'))
                        ->state(fn (Invoice $record): ?string => self::address($record->recipient))
                        ->placeholder(__('No billing address')),
                ]),
            Section::make(__('Lines'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('description')->label(__('Description')),
                            TextEntry::make('period')
                                ->label(__('Period'))
                                ->state(fn (InvoiceLine $record): ?string => $record->period_start ? trim($date($record->period_start).' – '.$date($record->period_end), ' –') : null)
                                ->placeholder('–'),
                            TextEntry::make('amount_cents')->label(__('Amount'))->state(fn (InvoiceLine $record): string => Money::format($record->amount_cents, $record->invoice->currency)),
                        ]),
                ]),
            Section::make(__('Payment'))
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Paid)
                ->schema([
                    TextEntry::make('paid_at')->label(__('Paid on'))->date('d.m.Y'),
                    TextEntry::make('paidBy.name')->label(__('Marked by'))->placeholder('–'),
                    TextEntry::make('payment_note')->label(__('Note'))->placeholder('–'),
                ]),
            Section::make(__('Cancelled'))
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Cancelled)
                ->schema([
                    TextEntry::make('cancelled_at')->label(__('Cancelled'))->dateTime(),
                    TextEntry::make('cancelledBy.name')->label(__('By'))->placeholder('–'),
                    TextEntry::make('cancel_reason')->label(__('Reason')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('recipient.name')
                    ->label(__('Recipient'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('recipient->name', 'like', "%{$search}%")
                        ->orWhere('recipient->email', 'like', "%{$search}%"))),
                TextColumn::make('total_cents')->label(__('Total'))->state(fn (Invoice $record): string => Money::format($record->total_cents, $record->currency))->alignEnd(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('issued_at')->label(__('Invoice date'))->date('d.m.Y')->sortable(),
                TextColumn::make('due_at')->label(__('Payable by'))->date('d.m.Y')->sortable(),
                TextColumn::make('paid_at')->label(__('Paid on'))->date('d.m.Y')->placeholder('–')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(InvoiceStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(InvoiceActions::all()),
            ]);
    }

    /**
     * @param  array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string, email?: ?string}  $address
     */
    public static function address(array $address): ?string
    {
        if (blank($address['city'])) {
            return null;
        }

        return implode(', ', array_filter([$address['street'], trim($address['postal_code'].' '.$address['city']), $address['country']]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}

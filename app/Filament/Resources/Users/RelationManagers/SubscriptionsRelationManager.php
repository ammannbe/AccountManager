<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Accounts\Suspensions;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Plans an admin assigned to the user; plans with a price are billed per period.
 */
class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Plans');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('plan_id')
                ->label(__('Plan'))
                ->relationship('plan', 'name', fn (Builder $query, string $operation) => $query->when($operation === 'create', fn (Builder $query) => $query->where('active', true)))
                ->getOptionLabelFromRecordUsing(fn (Plan $record): string => $record->service ? "{$record->name} ({$record->service->name})" : $record->name)
                ->required()
                ->disabledOn('edit'),
            DateTimePicker::make('starts_at')->label(__('Starts'))->default(now())->required()->seconds(false),
            DateTimePicker::make('ends_at')->label(__('Ends'))->helperText(__('Empty: until ended by hand.'))->after('starts_at')->seconds(false),
            Textarea::make('note')->label(__('Note'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('plan.name')->label(__('Plan')),
                TextColumn::make('plan.service.name')->label(__('Service'))->placeholder(__('Bundle')),
                TextColumn::make('plan.features.key')->label(__('Features'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                IconColumn::make('effective')->label(__('Effective'))->boolean()->state(fn (Subscription $record): bool => $record->isEffective()),
                TextColumn::make('starts_at')->label(__('Starts'))->dateTime(),
                TextColumn::make('ends_at')->label(__('Ends'))->dateTime()->placeholder('–'),
                TextColumn::make('billed_periods')->label(__('Billed periods'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Assign plan'))
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'status' => SubscriptionStatus::Active, 'created_by' => self::admin()?->getKey()]),
            ])
            ->recordActions([
                Action::make('end')
                    ->label(__('End'))
                    ->icon('heroicon-o-stop')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('The plan ends now; the apps get the new entitlements.'))
                    ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Ended)
                    ->action(fn (Subscription $record) => $record->end()),
                Action::make('suspend')
                    ->label(__('Suspend'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->modalDescription(__('The user gets the reason by mail; the apps get the new entitlements.'))
                    ->schema([
                        Textarea::make('reason')->label(__('Reason'))->required()->maxLength(1000),
                    ])
                    ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::Active)
                    ->action(fn (Subscription $record, array $data) => app(Suspensions::class)->suspend($record, (string) $data['reason'], self::admin())),
                Action::make('resume')
                    ->label(__('Reactivate'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->modalDescription(__('The user gets a mail that the plan is active again.'))
                    ->schema([
                        Textarea::make('message')->label(__('Message (optional)'))->maxLength(1000),
                    ])
                    ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::Suspended)
                    ->action(fn (Subscription $record, array $data) => app(Suspensions::class)->lift($record, $data['message'] ?? null, self::admin())),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function admin(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}

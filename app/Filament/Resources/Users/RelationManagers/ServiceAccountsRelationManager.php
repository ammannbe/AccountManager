<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Accounts\Access;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Connectors;
use App\Enums\AccountStatus;
use App\Models\ConnectorRun;
use App\Models\ServiceAccount;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class ServiceAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceAccounts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Services');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')
                ->label(__('Service'))
                ->relationship('service', 'name')
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('user_id', $this->getOwnerRecord()->getKey())),
            // Later changes go through the actions (suspending mails the reason).
            Select::make('status')->label(__('Status'))->options(AccountStatus::class)->default(AccountStatus::Active)->required()->visibleOn('create'),
            Toggle::make('is_admin')
                ->label(__('Admin in the service'))
                ->helperText(__('Sent to the app in the entitlements (and as admin group, if the service has one) while the account is active.')),
            TextInput::make('external_username')->label(__('Username in the service'))->helperText(__('For external services, shown to the user.'))->maxLength(255),
            TextInput::make('external_id')
                ->label(__('ID in the service'))
                ->helperText(__('Set by the connector (e.g. the Wizarr user id). Enter it by hand if a redeemed invitation could not be matched.'))
                ->maxLength(255),
            Textarea::make('note')->label(__('Note'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service'))
            ->columns([
                TextColumn::make('service.name')->label(__('Service')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('connector_status')
                    ->label(__('In the service'))
                    ->state(fn (ServiceAccount $record): ?string => app(Connectors::class)->status($record)?->label)
                    ->badge()
                    ->color(fn (ServiceAccount $record): string => app(Connectors::class)->status($record)->color ?? 'gray')
                    ->placeholder('–'),
                IconColumn::make('is_admin')->label(__('Admin'))->boolean(),
                TextColumn::make('external_username')->label(__('Username in the service')),
                TextColumn::make('approved_at')->label(__('Approved'))->date(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add service')),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ServiceAccount $record): bool => $record->status === AccountStatus::Requested)
                    ->action(fn (ServiceAccount $record) => app(Access::class)->approve($record)),
                Action::make('suspend')
                    ->label(__('Suspend'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->modalDescription(__('The user gets the reason by mail; the app gets the new entitlements.'))
                    ->schema([
                        Textarea::make('reason')->label(__('Reason'))->required()->maxLength(1000),
                    ])
                    ->visible(fn (ServiceAccount $record): bool => $record->status === AccountStatus::Active)
                    ->action(fn (ServiceAccount $record, array $data) => app(Access::class)->suspend($record, (string) $data['reason'], self::admin())),
                Action::make('reactivate')
                    ->label(__('Reactivate'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->modalDescription(__('The user gets a mail that the access is active again.'))
                    ->schema([
                        Textarea::make('message')->label(__('Message (optional)'))->maxLength(1000),
                    ])
                    ->visible(fn (ServiceAccount $record): bool => $record->status === AccountStatus::Suspended)
                    ->action(fn (ServiceAccount $record, array $data) => app(Access::class)->reactivate($record, $data['message'] ?? null, self::admin())),
                Action::make('connectorRetry')
                    ->label(__('Retry'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('Runs the connector step for the current status again (active: create the account or send the open invitation again; suspended: suspend it).'))
                    ->visible(fn (ServiceAccount $record): bool => self::hasConnector($record) && $record->status !== AccountStatus::Requested)
                    ->action(function (ServiceAccount $record): void {
                        app(Connectors::class)->resync($record);
                        Notification::make()->title(__('Queued.'))->success()->send();
                    }),
                Action::make('connectorLog')
                    ->label(__('Connector log'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->visible(fn (ServiceAccount $record): bool => self::hasConnector($record) || $record->connectorRuns()->exists())
                    ->modalHeading(fn (ServiceAccount $record): string => __('Connector log: :service', ['service' => $record->service->name]))
                    ->schema(fn (ServiceAccount $record): array => [
                        RepeatableEntry::make('runs')
                            ->hiddenLabel()
                            ->state($record->connectorRuns()->latest('id')->limit(20)->get()->map(fn (ConnectorRun $run): array => [
                                'created_at' => $run->created_at?->isoFormat('L LT'),
                                'action' => $run->action->getLabel(),
                                'status' => $run->status->getLabel(),
                                'attempts' => $run->attempts,
                                'message' => $run->message,
                            ])->all())
                            ->table([
                                TableColumn::make(__('Created')),
                                TableColumn::make(__('Action')),
                                TableColumn::make(__('Status')),
                                TableColumn::make(__('Attempts')),
                                TableColumn::make(__('Message')),
                            ])
                            ->schema([
                                TextEntry::make('created_at'),
                                TextEntry::make('action'),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('attempts'),
                                TextEntry::make('message')->placeholder('–'),
                            ])
                            ->placeholder(__('No connector runs yet.')),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function hasConnector(ServiceAccount $account): bool
    {
        return ! app(ConnectorRegistry::class)->isPassive($account->service->connector);
    }

    private static function admin(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}

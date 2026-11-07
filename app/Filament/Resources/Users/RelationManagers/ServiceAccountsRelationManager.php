<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Accounts\Access;
use App\Enums\AccountStatus;
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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
            Textarea::make('note')->label(__('Note'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('service.name')->label(__('Service')),
                TextColumn::make('status')->label(__('Status'))->badge(),
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

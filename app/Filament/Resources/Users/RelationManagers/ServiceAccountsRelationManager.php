<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Accounts\Access;
use App\Enums\AccountStatus;
use App\Models\ServiceAccount;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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
            Select::make('status')->label(__('Status'))->options(AccountStatus::class)->default(AccountStatus::Active)->required(),
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
                    ->visible(fn (ServiceAccount $record): bool => $record->status !== AccountStatus::Active)
                    ->action(fn (ServiceAccount $record) => app(Access::class)->approve($record)),
                Action::make('suspend')
                    ->label(__('Suspend'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ServiceAccount $record): bool => $record->status === AccountStatus::Active)
                    ->action(fn (ServiceAccount $record) => app(Access::class)->suspend($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\AccountStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                TextColumn::make('serviceAccounts.service.name')->label(__('Services'))->badge(),
                IconColumn::make('oidc_sub')->label(__('Linked'))->boolean()->getStateUsing(fn ($record): bool => $record->oidc_sub !== null),
                IconColumn::make('is_admin')->label(__('Admin'))->boolean(),
                TextColumn::make('created_at')->label(__('Created'))->date()->sortable(),
            ])
            ->filters([
                Filter::make('requested')
                    ->label(__('Open requests'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('serviceAccounts', fn (Builder $accounts) => $accounts->where('status', AccountStatus::Requested))),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}

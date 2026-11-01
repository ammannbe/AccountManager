<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('key')->label(__('Key'))->searchable(),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('access_group')->label(__('Group')),
                IconColumn::make('self_signup')->label(__('Self-registration'))->boolean(),
                IconColumn::make('requires_approval')->label(__('Requires approval'))->boolean(),
                IconColumn::make('visible')->label(__('Visible'))->boolean(),
                TextColumn::make('accounts_count')->label(__('Accounts'))->counts('accounts'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}

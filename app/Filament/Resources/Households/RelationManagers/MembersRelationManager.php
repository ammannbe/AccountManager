<?php

namespace App\Filament\Resources\Households\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Members');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('joined_at')
            ->columns([
                TextColumn::make('user.name')->label(__('Name')),
                TextColumn::make('user.email')->label(__('Email')),
                TextColumn::make('role')->label(__('Role'))->badge(),
                TextColumn::make('joined_at')->label(__('Joined'))->dateTime(),
            ]);
    }
}

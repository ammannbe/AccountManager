<?php

namespace App\Filament\Resources\Households\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Open invitations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('accepted_at'))
            ->columns([
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('role')->label(__('Role'))->badge(),
                TextColumn::make('inviter.name')->label(__('Invited by'))->placeholder('–'),
                TextColumn::make('expires_at')->label(__('Expires'))->dateTime(),
            ]);
    }
}

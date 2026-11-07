<?php

namespace App\Filament\Resources\Households\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What the apps reported as shared with the household.
 */
class SharesRelationManager extends RelationManager
{
    protected static string $relationship = 'shares';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Shared');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('service.name')->label(__('Service')),
                TextColumn::make('resource_type')->label(__('Type')),
                TextColumn::make('label')->label(__('Name')),
                TextColumn::make('updated_at')->label(__('Reported'))->dateTime(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Households;

use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Filament\Resources\Households\Pages\ViewHousehold;
use App\Filament\Resources\Households\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\Households\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Households\RelationManagers\SharesRelationManager;
use App\Models\Household;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only overview: users manage their households in the portal.
 */
class HouseholdResource extends Resource
{
    protected static ?string $model = Household::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('Household');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Households');
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
        return $schema->components([
            Section::make(__('Household'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('uuid')->label('UUID')->copyable()->fontFamily('mono'),
                    TextEntry::make('creator.name')->label(__('Created by'))->placeholder('–'),
                    TextEntry::make('created_at')->label(__('Created'))->dateTime(),
                    TextEntry::make('version')->label(__('Version')),
                    TextEntry::make('updated_at')->label(__('Changed'))->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('users.name')->label(__('Members'))->badge()->searchable(),
                TextColumn::make('shares_count')->label(__('Shared'))->counts('shares'),
                TextColumn::make('version')->label(__('Version')),
                TextColumn::make('created_at')->label(__('Created'))->date()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            InvitationsRelationManager::class,
            SharesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHouseholds::route('/'),
            'view' => ViewHousehold::route('/{record}'),
        ];
    }
}

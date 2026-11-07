<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Models\Feature;
use App\Models\Service;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Features of a service the AccountManager unlocks through plans or direct grants.
 */
class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'features';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Features');
    }

    public function form(Schema $schema): Schema
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord();

        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('key')
                ->label(__('Key'))
                ->helperText(__('Sent to the app in the entitlements; keep it stable. Convention: service key and name, e.g. :example.', ['example' => "{$service->key}.ai"]))
                ->required()
                ->maxLength(100)
                ->regex(Feature::KEY_PATTERN)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('service_id', $service->getKey())),
            TextInput::make('group')
                ->label(__('Group in the identity provider'))
                ->helperText(__('Optional: users with the feature get this group (e.g. feat-shoppinglist-ai), for apps that read it from the token.'))
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->notIn(fn (): array => array_values(array_filter([...Service::query()->pluck('access_group')->all(), ...Service::query()->pluck('admin_group')->all()]))),
            Textarea::make('description')->label(__('Description'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('key')->label(__('Key'))->fontFamily('mono'),
                TextColumn::make('group')->label(__('Group'))->placeholder('–'),
                TextColumn::make('plans.name')->label(__('Plans'))->badge(),
                TextColumn::make('grants_count')->label(__('Granted directly'))->counts('grants'),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add feature')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription(__('Plans and users lose the feature; the apps get the new entitlements.')),
            ]);
    }
}

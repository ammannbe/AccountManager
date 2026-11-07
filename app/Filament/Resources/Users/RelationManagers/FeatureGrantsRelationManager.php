<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Feature;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

/**
 * Features granted to the user directly, without a plan.
 */
class FeatureGrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'featureGrants';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Features granted directly');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('feature_id')
                ->label(__('Feature'))
                ->options(fn (): array => Feature::query()->with('service')->orderBy('service_id')->orderBy('name')->get()
                    ->groupBy(fn (Feature $feature): string => $feature->service->name)
                    ->map(fn ($features): array => $features->mapWithKeys(fn (Feature $feature): array => [$feature->id => "{$feature->name} ({$feature->key})"])->all())
                    ->all())
                ->searchable()
                ->required()
                ->disabledOn('edit')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('user_id', $this->getOwnerRecord()->getKey())),
            Textarea::make('note')->label(__('Note'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feature.service.name')->label(__('Service')),
                TextColumn::make('feature.name')->label(__('Feature')),
                TextColumn::make('feature.key')->label(__('Key'))->fontFamily('mono'),
                TextColumn::make('note')->label(__('Note'))->limit(60)->placeholder('–'),
                TextColumn::make('created_at')->label(__('Granted'))->date(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Grant feature'))
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'granted_by' => self::admin()?->getKey()]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label(__('Revoke'))->modalHeading(__('Revoke feature')),
            ]);
    }

    private static function admin(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}

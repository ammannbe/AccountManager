<?php

namespace App\Filament\Resources\Plans;

use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Feature;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Offers that unlock features: of one service, or a bundle across services. Admins assign them to
 * users; plans with a price are billed (App\Billing\Billing).
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('Plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Plans');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Plan'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    Select::make('service_id')
                        ->label(__('Service'))
                        ->relationship('service', 'name')
                        ->placeholder(__('All services (bundle)'))
                        ->helperText(__('Empty: a bundle with features of several services.'))
                        ->live(),
                    CheckboxList::make('features')
                        ->label(__('Features'))
                        ->relationship(
                            'features',
                            'name',
                            fn (Builder $query, Get $get) => $query
                                ->when($get('service_id'), fn (Builder $query, mixed $serviceId) => $query->where('features.service_id', $serviceId))
                                ->orderBy('features.service_id')
                                ->orderBy('features.name'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Feature $record): string => "{$record->service->name}: {$record->name} ({$record->key})")
                        ->helperText(__('Users with an active subscription get these features in the services where their account is active.'))
                        ->columnSpanFull(),
                    Textarea::make('description')->label(__('Description'))->helperText(__('Shown to the user in the portal.'))->columnSpanFull(),
                    Toggle::make('active')->label(__('Can be assigned'))->helperText(__('Existing subscriptions keep running when it is turned off.'))->default(true),
                ]),
            Section::make(__('Price'))
                ->description(__('Subscriptions of plans with a price and an interval are billed in advance for each period from their start; changes apply to the next invoices.'))
                ->columns(3)
                ->schema([
                    TextInput::make('price_cents')->label(__('Price in cents'))->numeric()->minValue(0)->integer(),
                    TextInput::make('currency')->label(__('Currency'))->placeholder(fn (): string => config('billing.currency', 'CHF'))->length(3)->helperText(__('Only CHF and EUR get a payment slip.')),
                    Select::make('interval')->label(__('Interval'))->options(PlanInterval::class),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('service.name')->label(__('Service'))->placeholder(__('Bundle')),
                TextColumn::make('features.key')->label(__('Features'))->badge(),
                TextColumn::make('subscriptions_count')
                    ->label(__('Active subscriptions'))
                    ->counts(['subscriptions' => fn (Builder $query) => $query->where('status', SubscriptionStatus::Active)]),
                IconColumn::make('active')->label(__('Can be assigned'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}

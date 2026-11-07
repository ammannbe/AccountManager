<?php

namespace App\Filament\Resources\WebhookDeliveries;

use App\Enums\WebhookStatus;
use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Log of the webhooks sent to the apps (pruned after 30 days).
 */
class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Webhook');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Webhooks');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable(),
                TextColumn::make('service.name')->label(__('Service')),
                TextColumn::make('event')->label(__('Event'))->searchable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('attempts')->label(__('Attempts')),
                TextColumn::make('response_status')->label(__('Response'))->placeholder('–'),
                TextColumn::make('last_error')->label(__('Last error'))->limit(60)->tooltip(fn (WebhookDelivery $record): ?string => $record->last_error)->placeholder('–'),
                TextColumn::make('event_id')->label(__('Event ID'))->fontFamily('mono')->copyable()->toggleable(isToggledHiddenByDefault: true)->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(WebhookStatus::class),
                SelectFilter::make('service')->label(__('Service'))->relationship('service', 'name'),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label(__('Send again'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (WebhookDelivery $record): bool => $record->status === WebhookStatus::Failed)
                    ->action(function (WebhookDelivery $record): void {
                        $record->update(['status' => WebhookStatus::Pending]);
                        DeliverWebhook::dispatch($record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
        ];
    }
}

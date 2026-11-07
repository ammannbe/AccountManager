<?php

namespace App\Filament\Resources\ConnectorRuns;

use App\Connectors\Connectors;
use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use App\Filament\Resources\ConnectorRuns\Pages\ListConnectorRuns;
use App\Models\ConnectorRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Log of the connector calls to external services (Wizarr, manual steps), with «Retry».
 */
class ConnectorRunResource extends Resource
{
    protected static ?string $model = ConnectorRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 11;

    public static function getModelLabel(): string
    {
        return __('Connector run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Connector log');
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
                TextColumn::make('user')->label(__('User'))->state(fn (ConnectorRun $record): string => $record->userLabel()),
                TextColumn::make('action')->label(__('Action'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('attempts')->label(__('Attempts')),
                TextColumn::make('message')->label(__('Message'))->limit(80)->tooltip(fn (ConnectorRun $record): ?string => $record->message)->placeholder('–'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ConnectorRunStatus::class),
                SelectFilter::make('action')->label(__('Action'))->options(ConnectorAction::class),
                SelectFilter::make('service')->label(__('Service'))->relationship('service', 'name'),
            ])
            ->recordActions([
                self::retryAction(),
            ]);
    }

    /**
     * Runs a failed call again (also for deprovisioning: it keeps the snapshot of the account).
     */
    public static function retryAction(): Action
    {
        return Action::make('retry')
            ->label(__('Retry'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (ConnectorRun $record): bool => $record->status === ConnectorRunStatus::Failed && $record->action !== ConnectorAction::Check)
            ->action(fn (ConnectorRun $record) => app(Connectors::class)->retry($record));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnectorRuns::route('/'),
        ];
    }
}

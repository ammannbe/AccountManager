<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rotateSecret')
                ->label(fn (Service $record): string => $record->secret_created_at ? __('Rotate secret') : __('Generate secret'))
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (Service $record): string => $record->secret_created_at
                    ? __('The current secret stops working right away: webhooks to the app and its API calls fail until the new one is set there.')
                    : __('The secret is shown only once.'))
                ->action(function (Service $record): void {
                    $this->replaceMountedAction('showSecret', ['secret' => $record->rotateSecret()]);
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * Shows the new secret once (opened by rotateSecret); it is stored encrypted and never displayed again.
     */
    public function showSecretAction(): Action
    {
        return Action::make('showSecret')
            ->modalHeading(__('New secret'))
            ->modalDescription(__('Copy it now into the configuration of the app (e.g. KONTO_SECRET); it is not shown again.'))
            ->schema(fn (array $arguments): array => [
                TextEntry::make('key')->label(__('Key'))->state($this->getRecord()->getAttribute('key'))->copyable()->fontFamily('mono'),
                TextEntry::make('secret')->label(__('Secret'))->state($arguments['secret'] ?? '')->copyable()->fontFamily('mono'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }
}

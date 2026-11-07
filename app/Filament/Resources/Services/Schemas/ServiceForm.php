<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\ServiceType;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Service'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                        TextInput::make('key')
                            ->label(__('Key'))
                            ->helperText(__('Used in the registration link, e.g. /register/recipes.'))
                            ->required()
                            ->alphaDash()
                            ->maxLength(50)
                            // "sent" is taken by /register/sent.
                            ->notIn(['sent'])
                            ->unique(ignoreRecord: true),
                        TextInput::make('url')->label(__('URL'))->url()->maxLength(255),
                        TextInput::make('login_url')
                            ->label(__('Login URL'))
                            ->helperText(__('Optional: starts the login of the service, e.g. https://recipes.example.com/auth/oidc/redirect. "Open" in the portal uses it instead of the URL.'))
                            ->url()
                            ->maxLength(255),
                        Select::make('type')->label(__('Type'))->options(ServiceType::class)->default(ServiceType::Oidc)->required(),
                        Textarea::make('description')->label(__('Description'))->columnSpanFull(),
                    ]),
                Section::make(__('Access'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('access_group')
                            ->label(__('Group in the identity provider'))
                            ->helperText(__('Active accounts get this group (e.g. svc-recipes); restrict the OIDC client to it.'))
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('position')->label(__('Position'))->numeric()->default(0)->required(),
                        Toggle::make('self_signup')->label(__('Self-registration'))->helperText(__('Anybody may register for this service.')),
                        Toggle::make('requires_approval')->label(__('Requires approval'))->helperText(__('An admin has to approve new accounts.')),
                        Toggle::make('visible')->label(__('Visible in the portal'))->default(true),
                    ]),
                Section::make(__('Households and webhooks'))
                    ->description(__('Apps with households get signed webhooks and call the API with the service key and the secret (see docs/HOUSEHOLDS-API.md).'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('webhook_url')
                            ->label(__('Webhook URL'))
                            ->helperText(__('e.g. https://recipes.example.com/api/account-manager/webhook'))
                            ->url()
                            ->maxLength(255),
                        TextEntry::make('secret_state')
                            ->label(__('Secret'))
                            ->state(fn (?Service $record): string => $record?->secret_created_at
                                ? __('Set on :date', ['date' => $record->secret_created_at->isoFormat('LLL')])
                                : __('Not set yet: generate it with the button at the top.')),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Connectors\ConnectorRegistry;
use App\Enums\ServiceType;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

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
                        TextInput::make('admin_group')
                            ->label(__('Admin group in the identity provider'))
                            ->helperText(__('Optional: active accounts marked as admin get this group (e.g. svc-recipes-admin), for apps with AUTH_ADMIN_SOURCE=account-manager.'))
                            ->maxLength(255)
                            ->different('access_group')
                            ->unique(ignoreRecord: true),
                        TextInput::make('position')->label(__('Position'))->numeric()->default(0)->required(),
                        Toggle::make('self_signup')->label(__('Self-registration'))->helperText(__('Anybody may register for this service.')),
                        Toggle::make('requires_approval')->label(__('Requires approval'))->helperText(__('An admin has to approve new accounts.')),
                        Toggle::make('visible')->label(__('Visible in the portal'))->default(true),
                    ]),
                Section::make(__('Connector'))
                    ->description(__('What happens in the service when access is granted, suspended or removed. The group in the identity provider is synced in any case.'))
                    ->columns(2)
                    ->schema([
                        Select::make('connector')
                            ->label(__('Connector'))
                            ->options(fn (): array => app(ConnectorRegistry::class)->options())
                            ->default(ConnectorRegistry::DEFAULT)
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('connector_config.base_url')
                            ->label(__('Wizarr URL'))
                            ->helperText(__('Reachable from the AccountManager, e.g. https://invite.example.com or an internal address.'))
                            ->url()
                            ->required()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        TextInput::make('connector_config.public_url')
                            ->label(__('Public Wizarr URL'))
                            ->helperText(__('Optional: base of the invitation links if the Wizarr URL is internal.'))
                            ->url()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        TextInput::make('wizarr_api_key')
                            ->label(__('API key'))
                            ->helperText(fn (?Service $record): string => filled($record?->connectorSetting('api_key'))
                                ? __('Set. Leave empty to keep it.')
                                : __('Wizarr → Settings → API keys.'))
                            ->password()
                            ->required(fn (?Service $record): bool => blank($record?->connectorSetting('api_key')))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        TextInput::make('connector_config.server_id')
                            ->label(__('Server ID in Wizarr'))
                            ->helperText(__('Id of the media server in Wizarr (GET /api/servers).'))
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        TagsInput::make('connector_config.library_ids')
                            ->label(__('Library IDs'))
                            ->helperText(__('Optional: Wizarr ids of the libraries (GET /api/libraries); empty = all enabled libraries.'))
                            ->nestedRecursiveRules(['integer', 'min:1'])
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        Select::make('connector_config.expires_in_days')
                            ->label(__('Invitation valid for'))
                            ->options([1 => __('1 day'), 7 => __('7 days'), 30 => __('30 days')])
                            ->placeholder(__('Unlimited'))
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        TextInput::make('connector_config.duration')
                            ->label(__('Access duration (days)'))
                            ->helperText(__('Optional: Wizarr ends the access after this many days; empty = unlimited.'))
                            ->integer()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        Toggle::make('connector_config.allow_downloads')
                            ->label(__('Allow downloads'))
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        Toggle::make('connector_config.allow_live_tv')
                            ->label(__('Allow live TV'))
                            ->visible(fn (Get $get): bool => $get('connector') === 'wizarr'),
                        Textarea::make('connector_config.instructions')
                            ->label(__('Instructions for the user'))
                            ->helperText(__('Shown in the portal to users with access, e.g. where and how to log in. No passwords.'))
                            ->maxLength(2000)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('connector') === 'manual'),
                    ]),
                Section::make(__('Webhooks and API'))
                    ->description(__('Connected apps get signed webhooks (households, entitlements, deleted users) and call the API with the service key and the secret (see docs/HOUSEHOLDS-API.md and docs/ENTITLEMENTS-API.md).'))
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

    /**
     * The form gets the connector config without the API key (it never goes to the browser).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillConnector(array $data, Service $record): array
    {
        $data['connector_config'] = Arr::except($record->connector_config ?? [], ['api_key']);

        return $data;
    }

    /**
     * Puts a new API key into the config or keeps the stored one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function saveConnector(array $data, ?Service $record = null): array
    {
        $config = array_filter((array) ($data['connector_config'] ?? []), fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        $apiKey = trim((string) ($data['wizarr_api_key'] ?? ''));

        if (($data['connector'] ?? null) === 'wizarr') {
            $config['api_key'] = $apiKey !== '' ? $apiKey : $record?->connectorSetting('api_key');
        }

        unset($data['wizarr_api_key']);
        $data['connector_config'] = array_filter($config, fn (mixed $value): bool => $value !== null) ?: null;

        return $data;
    }
}

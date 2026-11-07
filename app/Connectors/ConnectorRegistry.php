<?php

namespace App\Connectors;

use App\Models\Service;

/**
 * The connectors by key (services.connector). oidc-group is the default: access only through the
 * group in the identity provider (SyncUserGroups), so it creates no runs.
 */
class ConnectorRegistry
{
    public const DEFAULT = 'oidc-group';

    /**
     * @var array<string, class-string<ServiceConnector>>
     */
    private array $connectors = [
        self::DEFAULT => OidcGroupConnector::class,
        'wizarr' => WizarrConnector::class,
        'manual' => ManualConnector::class,
    ];

    /**
     * @param  class-string<ServiceConnector>  $class
     */
    public function extend(string $key, string $class): void
    {
        $this->connectors[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->connectors[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $labels = [
            self::DEFAULT => __('Group in the identity provider only'),
            'wizarr' => __('Wizarr (Emby, Jellyfin, Plex …)'),
            'manual' => __('By hand (admins get a mail)'),
        ];

        return array_combine(array_keys($this->connectors), array_map(fn (string $key): string => $labels[$key] ?? $key, array_keys($this->connectors)));
    }

    /**
     * Passive connectors do nothing besides the group sync, so no runs are logged for them.
     */
    public function isPassive(string $key): bool
    {
        return $key === self::DEFAULT;
    }

    public function connector(string $key): ServiceConnector
    {
        if (! $this->has($key)) {
            throw ConnectorException::permanent("Unknown connector \"{$key}\".");
        }

        return app($this->connectors[$key]);
    }

    public function for(Service $service): ServiceConnector
    {
        return $this->connector($service->connector);
    }
}

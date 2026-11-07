<?php

namespace App\Connectors;

/**
 * Connector specific state of an account for the portal and the panel, e.g. an open invitation.
 */
final readonly class ConnectorStatus
{
    /**
     * @param  'success'|'warning'|'danger'|'gray'  $color
     */
    public function __construct(
        public string $label,
        public string $color = 'gray',
        public ?string $detail = null,
        public ?string $url = null,
        public ?string $urlLabel = null,
    ) {}
}

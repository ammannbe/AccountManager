<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel
{
    case Oidc = 'oidc';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Oidc => __('Login through the identity provider'),
            self::External => __('External (accounts managed there)'),
        };
    }
}

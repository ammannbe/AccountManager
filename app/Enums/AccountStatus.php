<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AccountStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => __('Requested'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PlanInterval: string implements HasLabel
{
    case Month = 'month';
    case Year = 'year';
    case Once = 'once';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => __('Monthly'),
            self::Year => __('Yearly'),
            self::Once => __('Once'),
        };
    }
}

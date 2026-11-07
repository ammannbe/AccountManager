<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConnectorAction: string implements HasLabel
{
    case Provision = 'provision';
    case Suspend = 'suspend';
    case Resume = 'resume';
    case Deprovision = 'deprovision';
    // Scheduled check of the external state (e.g. a redeemed Wizarr invitation); logged only on changes.
    case Check = 'check';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provision => __('Provision'),
            self::Suspend => __('Suspend'),
            self::Resume => __('Resume'),
            self::Deprovision => __('Deprovision'),
            self::Check => __('Check'),
        };
    }
}

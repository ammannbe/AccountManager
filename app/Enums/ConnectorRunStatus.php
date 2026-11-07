<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConnectorRunStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    // An admin has to do it by hand (manual connector); they got a mail.
    case Manual = 'manual';
    // Not needed anymore, e.g. the account changed its status again before the run.
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Succeeded => __('Succeeded'),
            self::Manual => __('By hand'),
            self::Skipped => __('Skipped'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Manual => 'info',
            self::Skipped => 'gray',
            self::Failed => 'danger',
        };
    }
}

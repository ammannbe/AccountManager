<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WebhookStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Delivered => __('Delivered'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Delivered => 'success',
            self::Failed => 'danger',
        };
    }
}

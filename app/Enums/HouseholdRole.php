<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Admins invite and remove members, change roles, rename and delete the household; members may
 * leave. Every household keeps at least one admin. Same values as in the apps.
 */
enum HouseholdRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Member = 'member';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Member => __('Member'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Member => 'gray',
        };
    }
}

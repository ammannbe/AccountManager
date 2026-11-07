<?php

namespace App\Filament\Widgets;

use App\Identity\AdminUrl;
use Filament\Widgets\Widget;

/**
 * Dashboard card with a link to the Pocket ID admin UI (users, groups, OIDC clients).
 */
class PocketIdWidget extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.pocket-id';

    public static function canView(): bool
    {
        return AdminUrl::get() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['url' => AdminUrl::get()];
    }
}

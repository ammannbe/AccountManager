<?php

namespace App\Observers;

use App\Entitlements\Entitlements;
use App\Jobs\SyncUserGroups;
use App\Models\ServiceAccount;

class ServiceAccountObserver
{
    public function saved(ServiceAccount $account): void
    {
        if ($account->wasChanged('status') || $account->wasRecentlyCreated) {
            SyncUserGroups::dispatch($account->user);
        }

        if ($account->wasChanged(['status', 'is_admin']) || $account->wasRecentlyCreated) {
            app(Entitlements::class)->refresh($account->user, [$account->service]);
        }
    }

    public function deleted(ServiceAccount $account): void
    {
        SyncUserGroups::dispatch($account->user);
        app(Entitlements::class)->refresh($account->user, [$account->service]);
    }
}

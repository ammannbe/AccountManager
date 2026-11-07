<?php

namespace App\Observers;

use App\Connectors\Connectors;
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

    public function created(ServiceAccount $account): void
    {
        app(Connectors::class)->accountCreated($account);
    }

    public function updated(ServiceAccount $account): void
    {
        app(Connectors::class)->accountUpdated($account);
    }

    public function deleted(ServiceAccount $account): void
    {
        SyncUserGroups::dispatch($account->user);
        app(Entitlements::class)->refresh($account->user, [$account->service]);
        app(Connectors::class)->accountDeleted($account);
    }
}

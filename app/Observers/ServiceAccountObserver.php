<?php

namespace App\Observers;

use App\Jobs\SyncUserGroups;
use App\Models\ServiceAccount;

class ServiceAccountObserver
{
    public function saved(ServiceAccount $account): void
    {
        if ($account->wasChanged('status') || $account->wasRecentlyCreated) {
            SyncUserGroups::dispatch($account->user);
        }
    }

    public function deleted(ServiceAccount $account): void
    {
        SyncUserGroups::dispatch($account->user);
    }
}

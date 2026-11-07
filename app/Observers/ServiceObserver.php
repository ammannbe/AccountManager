<?php

namespace App\Observers;

use App\Entitlements\Entitlements;
use App\Jobs\SyncUserGroups;
use App\Models\Service;
use App\Models\User;

/**
 * A new key changes the snapshots of the service's users, new groups their groups in the identity
 * provider.
 */
class ServiceObserver
{
    public function updated(Service $service): void
    {
        $users = fn () => User::query()->whereNotNull('oidc_sub')->whereHas('serviceAccounts', fn ($accounts) => $accounts->where('service_id', $service->id))->get();

        if ($service->wasChanged('key')) {
            $users()->each(fn (User $user) => app(Entitlements::class)->refresh($user, [$service]));
        }

        if ($service->wasChanged(['access_group', 'admin_group'])) {
            $users()->each(fn (User $user) => SyncUserGroups::dispatch($user));
        }
    }
}

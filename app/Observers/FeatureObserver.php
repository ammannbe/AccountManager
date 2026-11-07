<?php

namespace App\Observers;

use App\Entitlements\Entitlements;
use App\Jobs\SyncUserGroups;
use App\Models\Feature;
use App\Models\User;

/**
 * A renamed or deleted feature changes the snapshots of everybody holding it; a changed group their
 * groups in the identity provider.
 */
class FeatureObserver
{
    /**
     * Holders of features being deleted (plan_features and grants go with them in the database).
     *
     * @var array<int, list<int>>
     */
    private static array $deleting = [];

    public function updated(Feature $feature): void
    {
        if ($feature->wasChanged('key')) {
            app(Entitlements::class)->refreshUsers($feature->holderIds());
        }

        if ($feature->wasChanged('group')) {
            User::query()->whereIn('id', $feature->holderIds())->whereNotNull('oidc_sub')->get()->each(fn (User $user) => SyncUserGroups::dispatch($user));
        }
    }

    public function deleting(Feature $feature): void
    {
        self::$deleting[spl_object_id($feature)] = $feature->holderIds();
    }

    public function deleted(Feature $feature): void
    {
        $holders = self::$deleting[spl_object_id($feature)] ?? [];
        unset(self::$deleting[spl_object_id($feature)]);

        app(Entitlements::class)->refreshUsers($holders);
    }
}

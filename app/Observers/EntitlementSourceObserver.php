<?php

namespace App\Observers;

use App\Entitlements\Entitlements;
use App\Models\FeatureGrant;
use App\Models\Subscription;
use App\Models\User;

/**
 * Subscriptions and direct grants change what a user may do: their snapshots are recomputed
 * (unchanged ones send nothing).
 */
class EntitlementSourceObserver
{
    public function saved(Subscription|FeatureGrant $model): void
    {
        $this->refresh($model);
    }

    public function deleted(Subscription|FeatureGrant $model): void
    {
        $this->refresh($model);
    }

    private function refresh(Subscription|FeatureGrant $model): void
    {
        $user = User::query()->whereKey($model->getAttribute('user_id'))->first();

        if ($user) {
            app(Entitlements::class)->refresh($user);
        }
    }
}

<?php

namespace App\Accounts;

use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\Suspended;
use App\Notifications\SuspensionLifted;
use Illuminate\Support\Facades\DB;

/**
 * Manual suspensions of service accounts and subscriptions (KONZEPT 5.4): nothing is suspended
 * automatically, the admin gives a reason and the user gets it by mail; lifting works the same.
 * The entitlements and groups follow through the observers.
 */
class Suspensions
{
    public function suspend(ServiceAccount|Subscription $target, string $reason, ?User $by = null): Suspension
    {
        $suspension = DB::transaction(function () use ($target, $reason, $by): Suspension {
            $target->update(['status' => $target instanceof ServiceAccount ? AccountStatus::Suspended : SubscriptionStatus::Suspended]);

            return Suspension::query()->create([
                'user_id' => $target->user_id,
                'service_account_id' => $target instanceof ServiceAccount ? $target->id : null,
                'subscription_id' => $target instanceof Subscription ? $target->id : null,
                'reason' => trim($reason),
                'suspended_by' => $by?->id,
                'suspended_at' => now(),
            ]);
        });

        $target->user->notify(new Suspended($suspension));

        return $suspension;
    }

    /**
     * Reactivates the account or subscription; an optional message goes into the mail.
     */
    public function lift(ServiceAccount|Subscription $target, ?string $message = null, ?User $by = null): void
    {
        $message = $message !== null && trim($message) !== '' ? trim($message) : null;

        $suspension = DB::transaction(function () use ($target, $message, $by): ?Suspension {
            $target->update($target instanceof ServiceAccount
                ? ['status' => AccountStatus::Active, 'approved_at' => $target->approved_at ?? now()]
                : ['status' => SubscriptionStatus::Active]);

            $open = $target->suspensions()->whereNull('lifted_at')->get();
            $open->each->update(['lifted_at' => now(), 'lifted_by' => $by?->id, 'lift_message' => $message]);

            return $open->last();
        });

        $target->user->notify(new SuspensionLifted($target, $message, $suspension));
    }
}

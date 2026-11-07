<?php

namespace App\Accounts;

use App\Enums\AccountStatus;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\AccessGranted;
use App\Notifications\AccessRequested;
use Illuminate\Support\Facades\Notification;

/**
 * Grants or requests access to a service; the group sync follows through the observer.
 */
class Access
{
    /**
     * Self-service: active right away for services with self-registration (unless they need an
     * approval), otherwise requested for an admin to decide.
     */
    public function claim(User $user, Service $service): ServiceAccount
    {
        $account = ServiceAccount::query()->firstOrNew(['user_id' => $user->id, 'service_id' => $service->id]);

        // Suspended accounts stay suspended, active ones stay active.
        if ($account->exists && $account->status !== AccountStatus::Requested) {
            return $account;
        }

        $immediate = $service->self_signup && ! $service->requires_approval;

        $account->status = $immediate ? AccountStatus::Active : AccountStatus::Requested;
        $account->approved_at = $immediate ? now() : null;
        $account->save();

        if (! $immediate && $account->wasRecentlyCreated) {
            Notification::send(User::query()->where('is_admin', true)->get(), new AccessRequested($account));
        }

        return $account;
    }

    public function approve(ServiceAccount $account): void
    {
        $wasRequested = $account->status === AccountStatus::Requested;

        $account->update(['status' => AccountStatus::Active, 'approved_at' => $account->approved_at ?? now()]);

        if ($wasRequested) {
            $account->user->notify(new AccessGranted($account));
        }
    }

    /**
     * Suspends the account by hand; the user gets the reason by mail.
     */
    public function suspend(ServiceAccount $account, string $reason = '', ?User $by = null): void
    {
        app(Suspensions::class)->suspend($account, $reason, $by);
    }

    /**
     * Lifts a suspension; the user gets a mail (with the optional message).
     */
    public function reactivate(ServiceAccount $account, ?string $message = null, ?User $by = null): void
    {
        app(Suspensions::class)->lift($account, $message, $by);
    }
}

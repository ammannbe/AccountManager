<?php

namespace App\Jobs;

use App\Identity\IdentityProvider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets the groups of a user in the identity provider from their active service accounts.
 */
class SyncUserGroups implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public User $user)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->user->getKey();
    }

    public function handle(IdentityProvider $provider): void
    {
        if (! $provider->manages() || $this->user->oidc_sub === null) {
            return;
        }

        $provider->syncGroups($this->user->oidc_sub, $this->user->desiredGroups(), Service::managedGroups());
    }
}

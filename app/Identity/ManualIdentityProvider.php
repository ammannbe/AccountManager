<?php

namespace App\Identity;

use LogicException;

/**
 * Reduced mode without a provider driver: users and groups are managed by hand in the provider.
 */
class ManualIdentityProvider implements IdentityProvider
{
    public function manages(): bool
    {
        return false;
    }

    public function findUserByEmail(string $email): ?IdentityUser
    {
        return null;
    }

    public function users(): array
    {
        return [];
    }

    public function createUser(string $email, string $name, ?string $locale = null): IdentityUser
    {
        throw new LogicException('The identity provider is managed by hand.');
    }

    public function updateUser(string $userId, string $name, ?string $email = null): void
    {
        // Nothing to do: the admin keeps the provider up to date.
    }

    public function syncGroups(string $userId, array $groups, array $managed): void
    {
        // Nothing to do: the admin sets the groups in the provider.
    }

    public function sendLoginCode(string $userId): void
    {
        // Nothing to do.
    }
}

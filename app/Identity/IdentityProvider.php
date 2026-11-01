<?php

namespace App\Identity;

/**
 * Admin access to the identity provider. OIDC covers the login, but creating users and
 * setting groups is provider specific (see KONZEPT.md, "Identitätsanbieter").
 */
interface IdentityProvider
{
    /**
     * Whether users and groups can be managed (false: reduced mode, the admin manages them by hand).
     */
    public function manages(): bool;

    public function findUserByEmail(string $email): ?IdentityUser;

    /**
     * @return list<IdentityUser>
     */
    public function users(): array;

    /**
     * Creates a user with a verified e-mail address (the AccountManager verified it).
     */
    public function createUser(string $email, string $name, ?string $locale = null): IdentityUser;

    /**
     * Updates the profile of a user. A given e-mail address is set as verified (the AccountManager
     * verified it); without one, the address and its verification stay as they are.
     */
    public function updateUser(string $userId, string $name, ?string $email = null): void;

    /**
     * Sets the managed groups of a user to $groups; groups outside $managed stay untouched.
     *
     * @param  list<string>  $groups
     * @param  list<string>  $managed
     */
    public function syncGroups(string $userId, array $groups, array $managed): void;

    /**
     * Sends the user a one-time login code by e-mail (first login, setting up a passkey).
     */
    public function sendLoginCode(string $userId): void;
}

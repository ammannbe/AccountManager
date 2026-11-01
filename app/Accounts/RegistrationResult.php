<?php

namespace App\Accounts;

use App\Models\ServiceAccount;
use App\Models\User;

final readonly class RegistrationResult
{
    public function __construct(
        public User $user,
        // Null for a registration without a service.
        public ?ServiceAccount $account,
        // A new identity got a login code to set up the passkey.
        public bool $loginCodeSent,
        // False in the reduced mode: an admin still has to create the identity.
        public bool $automatic,
    ) {}
}

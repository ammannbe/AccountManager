<?php

namespace App\Accounts;

use App\Models\ServiceAccount;

final readonly class RegistrationResult
{
    public function __construct(
        public ServiceAccount $account,
        // A new identity got a login code to set up the passkey.
        public bool $loginCodeSent,
        // False in the reduced mode: an admin still has to create the identity.
        public bool $automatic,
    ) {}
}

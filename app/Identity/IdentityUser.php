<?php

namespace App\Identity;

/**
 * A user of the identity provider.
 */
final readonly class IdentityUser
{
    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $id,
        public ?string $email,
        public string $name,
        public bool $disabled = false,
        public array $groups = [],
    ) {}
}

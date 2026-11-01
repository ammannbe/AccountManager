<?php

namespace Tests\Fakes;

use App\Identity\IdentityProvider;
use App\Identity\IdentityUser;

class FakeIdentityProvider implements IdentityProvider
{
    /** @var array<string, IdentityUser> */
    public array $users = [];

    /** @var array<string, list<string>> */
    public array $groups = [];

    /** @var list<string> */
    public array $loginCodes = [];

    public function __construct(public bool $manages = true) {}

    public function manages(): bool
    {
        return $this->manages;
    }

    public function findUserByEmail(string $email): ?IdentityUser
    {
        foreach ($this->users as $user) {
            if (mb_strtolower((string) $user->email) === mb_strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    public function users(): array
    {
        return array_values($this->users);
    }

    public function createUser(string $email, string $name, ?string $locale = null): IdentityUser
    {
        $id = 'idp-'.(count($this->users) + 1);

        return $this->users[$id] = new IdentityUser($id, $email, $name);
    }

    public function syncGroups(string $userId, array $groups, array $managed): void
    {
        $kept = array_values(array_diff($this->groups[$userId] ?? [], $managed));
        $this->groups[$userId] = array_values(array_unique([...$kept, ...$groups]));
        sort($this->groups[$userId]);
    }

    public function sendLoginCode(string $userId): void
    {
        $this->loginCodes[] = $userId;
    }
}

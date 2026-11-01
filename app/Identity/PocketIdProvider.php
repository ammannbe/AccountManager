<?php

namespace App\Identity;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Pocket ID admin API (https://pocket-id.org/docs/api). The user id is the "sub" of the tokens.
 */
class PocketIdProvider implements IdentityProvider
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly string $loginCodeTtl = '1h',
        private readonly int $timeout = 15,
    ) {}

    public function manages(): bool
    {
        return true;
    }

    public function findUserByEmail(string $email): ?IdentityUser
    {
        $email = mb_strtolower(trim($email));

        foreach ($this->users() as $user) {
            if ($user->email !== null && mb_strtolower($user->email) === $email) {
                return $user;
            }
        }

        return null;
    }

    public function users(): array
    {
        return array_map($this->toUser(...), $this->paginate('/api/users'));
    }

    public function createUser(string $email, string $name, ?string $locale = null): IdentityUser
    {
        $response = $this->send('post', '/api/users', [
            'username' => $this->uniqueUsername($email),
            'email' => $email,
            'emailVerified' => true,
            ...$this->names($name, $email),
            'locale' => $locale,
            'isAdmin' => false,
        ]);

        return $this->toUser((array) $response->json());
    }

    public function updateUser(string $userId, string $name, ?string $email = null): void
    {
        $current = (array) $this->send('get', "/api/users/{$userId}")->json();

        // The update replaces the whole user: everything not changed here is sent back as it is.
        $this->send('put', "/api/users/{$userId}", [
            'username' => $current['username'] ?? '',
            'email' => $email ?? ($current['email'] ?? null),
            'emailVerified' => $email !== null ? true : (bool) ($current['emailVerified'] ?? false),
            ...$this->names($name, $email ?? (string) ($current['email'] ?? '')),
            'locale' => $current['locale'] ?? null,
            'isAdmin' => (bool) ($current['isAdmin'] ?? false),
            'disabled' => (bool) ($current['disabled'] ?? false),
        ]);
    }

    /**
     * @return array{firstName: string, lastName: string, displayName: string}
     */
    private function names(string $name, string $email): array
    {
        $name = trim($name) !== '' ? trim($name) : Str::before($email, '@');
        $firstName = Str::before($name, ' ');
        $lastName = trim(Str::after($name, $firstName));

        return [
            'firstName' => Str::limit($firstName, 50, ''),
            'lastName' => Str::limit($lastName, 50, ''),
            'displayName' => Str::limit($name, 100, ''),
        ];
    }

    public function syncGroups(string $userId, array $groups, array $managed): void
    {
        $groupIds = $this->groupIds($groups);

        $current = (array) $this->send('get', "/api/users/{$userId}/groups")->json();

        // Groups the AccountManager does not manage stay as they are.
        $kept = collect($current)
            ->reject(fn (array $group): bool => in_array($group['name'] ?? null, $managed, true))
            ->pluck('id');

        $ids = $kept->merge(array_values($groupIds))->unique()->values()->all();

        $this->send('put', "/api/users/{$userId}/user-groups", ['userGroupIds' => $ids]);
    }

    public function sendLoginCode(string $userId): void
    {
        $this->send('post', "/api/users/{$userId}/one-time-access-email", ['ttl' => $this->loginCodeTtl]);
    }

    /**
     * Ids of the groups by name, creating missing ones.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function groupIds(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $existing = collect($this->paginate('/api/user-groups'))->pluck('id', 'name')->all();

        $ids = [];

        foreach ($names as $name) {
            $ids[$name] = $existing[$name]
                ?? (string) $this->send('post', '/api/user-groups', ['friendlyName' => $name, 'name' => $name])->json('id');
        }

        return $ids;
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::of(Str::before($email, '@'))->lower()->ascii()->replaceMatches('/[^a-z0-9._-]+/', '')->limit(40, '')->toString();
        $base = $base !== '' ? $base : 'user';

        $taken = collect($this->paginate('/api/users'))->pluck('username')->map(fn ($name) => mb_strtolower((string) $name))->all();

        $username = $base;
        $suffix = 2;

        while (in_array($username, $taken, true)) {
            $username = $base.$suffix++;
        }

        return $username;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->send('get', $path, ['pagination' => ['page' => $page, 'limit' => self::PAGE_SIZE]]);
            /** @var list<array<string, mixed>> $data */
            $data = array_values((array) $response->json('data', []));
            $items = [...$items, ...$data];
            $totalPages = (int) $response->json('pagination.totalPages', 1);
            $page++;
        } while ($data !== [] && $page <= $totalPages);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function toUser(array $user): IdentityUser
    {
        return new IdentityUser(
            id: (string) $user['id'],
            email: isset($user['email']) ? (string) $user['email'] : null,
            name: (string) (($user['displayName'] ?? '') ?: trim(($user['firstName'] ?? '').' '.($user['lastName'] ?? ''))),
            disabled: (bool) ($user['disabled'] ?? false),
            groups: array_values(array_map('strval', array_filter(array_column((array) ($user['userGroups'] ?? []), 'name')))),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data = []): Response
    {
        try {
            return $this->client()->{$method}($path, $data)->throw();
        } catch (RequestException|ConnectionException $exception) {
            throw new IdentityProviderException("Pocket ID {$method} {$path} failed: ".$exception->getMessage(), previous: $exception);
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->url)
            ->withHeaders(['X-API-KEY' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}

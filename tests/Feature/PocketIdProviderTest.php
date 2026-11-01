<?php

namespace Tests\Feature;

use App\Identity\IdentityProviderException;
use App\Identity\PocketIdProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PocketIdProviderTest extends TestCase
{
    private function provider(): PocketIdProvider
    {
        return new PocketIdProvider('https://login.example.com', 'api-key', '1h');
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    private function page(array $data, int $totalPages = 1): array
    {
        return ['data' => $data, 'pagination' => ['totalPages' => $totalPages, 'currentPage' => 1]];
    }

    public function test_users_are_read_from_all_pages(): void
    {
        Http::fake([
            'login.example.com/api/users?pagination%5Bpage%5D=1*' => Http::response($this->page([
                ['id' => 'u1', 'username' => 'anna', 'email' => 'anna@example.com', 'displayName' => 'Anna', 'userGroups' => [['name' => 'svc-recipes']]],
            ], 2)),
            'login.example.com/api/users?pagination%5Bpage%5D=2*' => Http::response($this->page([
                ['id' => 'u2', 'username' => 'ben', 'email' => 'Ben@Example.com', 'firstName' => 'Ben', 'lastName' => 'Muster'],
            ], 2)),
        ]);

        $users = $this->provider()->users();

        $this->assertSame(['u1', 'u2'], array_map(fn ($user) => $user->id, $users));
        $this->assertSame(['svc-recipes'], $users[0]->groups);
        $this->assertSame('Ben Muster', $users[1]->name);
        $this->assertSame('u2', $this->provider()->findUserByEmail('ben@example.com')?->id);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-KEY', 'api-key'));
    }

    public function test_creating_a_user_picks_a_free_username_and_verifies_the_email(): void
    {
        Http::fake([
            'login.example.com/api/users?*' => Http::response($this->page([['id' => 'u1', 'username' => 'anna']])),
            'login.example.com/api/users' => Http::response(['id' => 'new-id', 'email' => 'anna@example.com', 'displayName' => 'Anna Muster']),
        ]);

        $user = $this->provider()->createUser('anna@example.com', 'Anna Muster', 'de');

        $this->assertSame('new-id', $user->id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['username'] === 'anna2'
            && $request['emailVerified'] === true
            && $request['firstName'] === 'Anna'
            && $request['lastName'] === 'Muster'
            && $request['isAdmin'] === false);
    }

    public function test_syncing_groups_keeps_unmanaged_ones_and_creates_missing_groups(): void
    {
        Http::fake([
            'login.example.com/api/user-groups?*' => Http::response($this->page([['id' => 'g-recipes', 'name' => 'svc-recipes']])),
            'login.example.com/api/user-groups' => Http::response(['id' => 'g-tasks', 'name' => 'svc-chorelist']),
            'login.example.com/api/users/u1/groups' => Http::response([
                ['id' => 'g-family', 'name' => 'family'],
                ['id' => 'g-cloud', 'name' => 'svc-cloud'],
            ]),
            'login.example.com/api/users/u1/user-groups' => Http::response(['id' => 'u1']),
        ]);

        $this->provider()->syncGroups('u1', ['svc-recipes', 'svc-chorelist'], ['svc-recipes', 'svc-chorelist', 'svc-cloud']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/user-groups')
            && $request['name'] === 'svc-chorelist');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['userGroupIds'] === ['g-family', 'g-recipes', 'g-tasks']);
    }

    public function test_updating_a_user_keeps_the_fields_it_does_not_change(): void
    {
        Http::fake([
            'login.example.com/api/users/u1' => Http::sequence()
                ->push(['id' => 'u1', 'username' => 'anna', 'email' => 'anna@example.com', 'emailVerified' => false, 'locale' => 'de', 'isAdmin' => true, 'disabled' => false])
                ->push(['id' => 'u1'])
                ->push(['id' => 'u1', 'username' => 'anna', 'email' => 'anna@example.com', 'emailVerified' => false, 'locale' => 'de', 'isAdmin' => true, 'disabled' => false])
                ->push(['id' => 'u1']),
        ]);

        $this->provider()->updateUser('u1', 'Anna Beispiel');
        $this->provider()->updateUser('u1', 'Anna Beispiel', 'anna@new.example.com');

        $updates = Http::recorded(fn (Request $request): bool => $request->method() === 'PUT')->values();
        $this->assertCount(2, $updates);

        [$nameOnly] = $updates[0];
        $this->assertSame('anna', $nameOnly['username']);
        $this->assertSame('anna@example.com', $nameOnly['email']);
        $this->assertFalse($nameOnly['emailVerified']);
        $this->assertTrue($nameOnly['isAdmin']);
        $this->assertSame('de', $nameOnly['locale']);
        $this->assertSame('Anna', $nameOnly['firstName']);
        $this->assertSame('Beispiel', $nameOnly['lastName']);

        [$withEmail] = $updates[1];
        $this->assertSame('anna@new.example.com', $withEmail['email']);
        $this->assertTrue($withEmail['emailVerified']);
    }

    public function test_the_login_code_is_mailed_by_pocket_id(): void
    {
        Http::fake(['login.example.com/api/users/u1/one-time-access-email' => Http::response(null, 204)]);

        $this->provider()->sendLoginCode('u1');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['ttl'] === '1h');
    }

    public function test_api_errors_raise_an_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectException(IdentityProviderException::class);

        $this->provider()->sendLoginCode('u1');
    }
}

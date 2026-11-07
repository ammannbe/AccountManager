<?php

namespace Tests\Feature;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class HouseholdInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'secret-invitation-token';

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->household = Household::factory()->withAdmin(User::factory()->create(['name' => 'Anna']))->create(['name' => 'Familie Muster']);
    }

    private function invite(string $email = 'carla@example.com', HouseholdRole $role = HouseholdRole::Member): HouseholdInvitation
    {
        return HouseholdInvitation::factory()->for($this->household)->token(self::TOKEN)->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $this->household->created_by,
        ]);
    }

    public function test_the_link_only_shows_the_invitation(): void
    {
        $invitation = $this->invite();
        $user = User::factory()->create(['email' => 'carla@example.com']);

        $this->actingAs($user)->get(route('household-invitations.show', self::TOKEN))
            ->assertOk()
            ->assertSee('Anna invites you to the household')
            ->assertSee('Join household');

        // Mail scanners open links: nothing happens without the POST.
        $this->assertFalse($this->household->hasMember($user));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_the_invited_user_accepts_with_the_role_of_the_invitation(): void
    {
        $invitation = $this->invite(role: HouseholdRole::Admin);
        $user = User::factory()->create(['email' => 'Carla@Example.com']);

        $this->actingAs($user)->post(route('household-invitations.accept-token', self::TOKEN))
            ->assertRedirect(route('households.index'))
            ->assertSessionHas('status', 'You joined the household "Familie Muster".');

        $this->assertSame(HouseholdRole::Admin, $this->household->roleOf($user));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(2, $this->household->fresh()->version);

        // The token cannot be used again.
        $this->actingAs($user)->post(route('household-invitations.accept-token', self::TOKEN))->assertSessionHasErrors('household');
    }

    public function test_pending_invitations_are_accepted_or_declined_on_the_households_page(): void
    {
        $invitation = $this->invite();
        $other = HouseholdInvitation::factory()->create(['email' => 'carla@example.com']);
        $user = User::factory()->create(['email' => 'carla@example.com']);

        $this->actingAs($user)->get(route('households.index'))->assertSee('Anna invites you to the household')->assertSee('Join household');

        $this->actingAs($user)->post(route('household-invitations.accept', $invitation))->assertSessionHas('status');
        $this->assertTrue($this->household->hasMember($user));

        $this->actingAs($user)->post(route('household-invitations.decline', $other))->assertSessionHas('status', 'Invitation declined.');
        $this->assertModelMissing($other);
        $this->assertFalse($other->household->hasMember($user));
    }

    public function test_declining_by_link(): void
    {
        $invitation = $this->invite();
        $user = User::factory()->create(['email' => 'carla@example.com']);

        $this->actingAs($user)->post(route('household-invitations.decline-token', self::TOKEN))->assertSessionHas('status', 'Invitation declined.');

        $this->assertModelMissing($invitation);
        $this->assertFalse($this->household->hasMember($user));
    }

    public function test_another_address_cannot_accept_or_decline(): void
    {
        $invitation = $this->invite();
        $user = User::factory()->create(['email' => 'mallory@example.com']);

        $this->actingAs($user)->get(route('household-invitations.show', self::TOKEN))->assertSee('This invitation is for carla@example.com')->assertDontSee('Join household');
        $this->actingAs($user)->post(route('household-invitations.accept-token', self::TOKEN))->assertSessionHasErrors('household');
        $this->actingAs($user)->post(route('household-invitations.accept', $invitation))->assertSessionHasErrors('household');
        $this->actingAs($user)->post(route('household-invitations.decline', $invitation))->assertSessionHasErrors('household');

        $this->assertFalse($this->household->hasMember($user));
        $this->assertModelExists($invitation);
    }

    public function test_an_unverified_address_cannot_accept(): void
    {
        $this->invite();
        $user = User::factory()->unverified()->create(['email' => 'carla@example.com']);

        $this->actingAs($user)->get(route('households.index'))->assertSee('Confirm your email address in your profile to join.');
        $this->actingAs($user)->post(route('household-invitations.accept-token', self::TOKEN))->assertSessionHasErrors('household');

        $this->assertFalse($this->household->hasMember($user));
    }

    public function test_expired_invitations_are_invalid(): void
    {
        $invitation = HouseholdInvitation::factory()->for($this->household)->token(self::TOKEN)->expired()->create(['email' => 'carla@example.com']);
        $user = User::factory()->create(['email' => 'carla@example.com']);

        $this->actingAs($user)->get(route('household-invitations.show', self::TOKEN))->assertSee('This invitation is invalid or has expired.');
        $this->actingAs($user)->post(route('household-invitations.accept-token', self::TOKEN))->assertSessionHasErrors('household');
        $this->actingAs($user)->post(route('household-invitations.accept', $invitation))->assertSessionHasErrors('household');
        $this->actingAs($user)->get(route('households.index'))->assertDontSee('Join household');

        $this->assertFalse($this->household->hasMember($user));
    }

    public function test_guests_log_in_and_come_back_to_the_invitation(): void
    {
        config(['login.oidc.enabled' => true]);
        $this->invite();
        $user = User::factory()->create(['email' => 'carla@example.com', 'oidc_sub' => 'u1']);

        $this->get(route('household-invitations.show', self::TOKEN))
            ->assertOk()
            ->assertSee('Log in with carla@example.com to join.')
            ->assertSee(route('login'))
            ->assertDontSee('Join household');

        $this->post(route('household-invitations.accept-token', self::TOKEN))->assertRedirect(route('login'));

        $identity = (new SocialiteUser)->setRaw(['sub' => 'u1', 'email' => 'carla@example.com', 'email_verified' => true])
            ->map(['id' => 'u1', 'email' => 'carla@example.com', 'name' => 'Carla']);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        $this->get(route('oidc.callback'))->assertRedirect(route('household-invitations.show', self::TOKEN));
        $this->assertAuthenticatedAs($user);

        $this->post(route('household-invitations.accept-token', self::TOKEN))->assertSessionHas('status');
        $this->assertTrue($this->household->hasMember($user));
    }

    public function test_guests_without_an_account_can_create_one(): void
    {
        config(['login.signup' => true]);
        $this->fakeIdentityProvider();
        $this->invite();

        $this->get(route('household-invitations.show', self::TOKEN))
            ->assertOk()
            ->assertSee(route('registrations.account'))
            ->assertSessionHas('url.intended', route('household-invitations.show', self::TOKEN));
    }

    public function test_members_opening_the_link_go_to_their_households(): void
    {
        $this->invite();
        $user = User::factory()->create(['email' => 'carla@example.com']);
        $this->household->members()->create(['user_id' => $user->id, 'role' => HouseholdRole::Member, 'joined_at' => now()]);

        $this->actingAs($user)->get(route('household-invitations.show', self::TOKEN))->assertRedirect(route('households.index'));
    }
}

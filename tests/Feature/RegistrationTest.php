<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Registration;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\AccessRequested;
use App\Notifications\ConfirmRegistration;
use App\Notifications\ManualSetupNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function register(Service $service, array $data = [], int $secondsAgo = 5): TestResponse
    {
        return $this->post(route('registrations.store', $service), [
            'name' => 'New Person',
            'email' => 'New.Person@example.com',
            'rendered_at' => Crypt::encryptString((string) now()->subSeconds($secondsAgo)->getTimestamp()),
            ...$data,
        ]);
    }

    private function token(Service $service, string $email = 'new.person@example.com'): string
    {
        $token = Registration::newToken();

        Registration::query()->create([
            'service_id' => $service->id,
            'email' => $email,
            'name' => 'New Person',
            'token_hash' => Registration::hash($token),
            'expires_at' => now()->addDays(7),
        ]);

        return $token;
    }

    public function test_only_services_with_self_registration_have_a_form(): void
    {
        $this->get(route('registrations.create', Service::factory()->create()))->assertNotFound();
        $this->get(route('registrations.create', Service::factory()->selfSignup()->create(['visible' => false])))->assertNotFound();
        $this->get(route('registrations.create', Service::factory()->selfSignup()->create()))->assertOk()->assertSee('name="website"', false);
    }

    public function test_registering_mails_a_confirmation_link(): void
    {
        $service = Service::factory()->selfSignup()->create();

        $this->register($service)->assertRedirect(route('registrations.sent', $service));

        $this->assertDatabaseHas('registrations', ['email' => 'new.person@example.com', 'confirmed_at' => null]);
        Notification::assertSentTo(new AnonymousNotifiable, ConfirmRegistration::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new.person@example.com');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public function test_known_addresses_get_the_same_answer(): void
    {
        $service = Service::factory()->selfSignup()->create();
        User::factory()->create(['email' => 'new.person@example.com']);

        $this->register($service)->assertRedirect(route('registrations.sent', $service));
    }

    public function test_the_same_address_is_not_mailed_again_right_away(): void
    {
        $service = Service::factory()->selfSignup()->create();

        $this->register($service);
        $this->register($service);

        $this->assertSame(1, Registration::query()->count());
    }

    public function test_the_honeypot_and_the_time_trap_reject_bots(): void
    {
        $service = Service::factory()->selfSignup()->create();

        $this->register($service, ['website' => 'https://spam.example.com'])->assertSessionHasErrors('email');
        $this->register($service, secondsAgo: 0)->assertSessionHasErrors('email');
        $this->register($service, ['rendered_at' => 'tampered'])->assertSessionHasErrors('email');

        $this->assertSame(0, Registration::query()->count());
        Notification::assertNothingSent();
    }

    public function test_opening_the_link_does_not_confirm_yet(): void
    {
        $this->fakeIdentityProvider();
        $token = $this->token(Service::factory()->selfSignup()->create());

        $this->get(route('registrations.show', ['token' => $token]))->assertOk()->assertSee(route('registrations.confirm', ['token' => $token]), false);

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public function test_confirming_creates_the_user_identity_and_access(): void
    {
        $idp = $this->fakeIdentityProvider();
        $service = Service::factory()->selfSignup()->create(['access_group' => 'svc-recipes']);
        $token = $this->token($service);

        $this->post(route('registrations.confirm', ['token' => $token]))->assertOk()->assertSee('login code');

        $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertSame('idp-1', $user->oidc_sub);
        $this->assertTrue($user->email_verified_at !== null);
        $this->assertSame(AccountStatus::Active, $user->serviceAccounts()->firstOrFail()->status);
        $this->assertSame(['svc-recipes'], $idp->groups['idp-1']);
        $this->assertSame(['idp-1'], $idp->loginCodes);

        // The link works once.
        $this->post(route('registrations.confirm', ['token' => $token]))->assertRedirect(route('registrations.show', ['token' => $token]));
    }

    public function test_an_existing_identity_is_linked_without_a_login_code(): void
    {
        $idp = $this->fakeIdentityProvider();
        $existing = $idp->createUser('new.person@example.com', 'New Person');
        $service = Service::factory()->selfSignup()->create();

        $this->post(route('registrations.confirm', ['token' => $this->token($service)]))->assertOk();

        $this->assertSame($existing->id, User::query()->where('email', 'new.person@example.com')->value('oidc_sub'));
        $this->assertSame([], $idp->loginCodes);
    }

    public function test_services_with_approval_request_the_access(): void
    {
        $idp = $this->fakeIdentityProvider();
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->selfSignup(requiresApproval: true)->create();

        $this->post(route('registrations.confirm', ['token' => $this->token($service)]))->assertOk();

        $account = ServiceAccount::query()->firstOrFail();
        $this->assertSame(AccountStatus::Requested, $account->status);
        $this->assertSame([], $idp->groups['idp-1'] ?? []);
        Notification::assertSentTo($admin, AccessRequested::class);
    }

    public function test_in_the_manual_mode_the_admins_set_up_the_identity(): void
    {
        $this->fakeIdentityProvider(manages: false);
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->selfSignup()->create();

        $this->post(route('registrations.confirm', ['token' => $this->token($service)]))->assertOk();

        $this->assertNull(User::query()->where('email', 'new.person@example.com')->value('oidc_sub'));
        Notification::assertSentTo($admin, ManualSetupNeeded::class);
    }

    public function test_expired_links_are_rejected(): void
    {
        $this->fakeIdentityProvider();
        $service = Service::factory()->selfSignup()->create();
        $token = $this->token($service);
        $this->travel(8)->days();

        $this->get(route('registrations.show', ['token' => $token]))->assertOk()->assertSee('Link not valid');
        $this->post(route('registrations.confirm', ['token' => $token]))->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public function test_old_registrations_are_pruned(): void
    {
        $service = Service::factory()->selfSignup()->create();
        $this->token($service);
        $this->travel(8)->days();

        $command = $this->artisan('registrations:prune');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        $this->assertSame(0, Registration::query()->count());
    }
}

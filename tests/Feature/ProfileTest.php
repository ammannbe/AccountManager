<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Fakes\FakeIdentityProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdentityProvider $idp;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->idp = $this->fakeIdentityProvider();
        $identity = $this->idp->createUser('anna@example.com', 'Anna Muster');
        $this->user = User::factory()->create(['name' => 'Anna Muster', 'email' => 'anna@example.com', 'oidc_sub' => $identity->id]);
    }

    private function emailLink(string $email, string $from = 'anna@example.com'): string
    {
        return URL::temporarySignedRoute('profile.email', now()->addDay(), ['user' => $this->user->id, 'email' => $email, 'from' => $from]);
    }

    /**
     * @phpstan-impure
     */
    private function storedEmail(): mixed
    {
        return User::query()->whereKey($this->user->id)->value('email');
    }

    public function test_guests_cannot_open_the_profile(): void
    {
        $this->get(route('profile'))->assertRedirect(route('login'));
    }

    public function test_the_profile_links_the_passkeys_of_the_provider(): void
    {
        config(['identity.account_url' => 'https://login.example.com/settings/account']);

        $this->actingAs($this->user)->get(route('profile'))->assertOk()->assertSee('https://login.example.com/settings/account', false);
    }

    public function test_the_name_is_saved_in_the_provider_too(): void
    {
        $this->actingAs($this->user)->put(route('profile.update'), ['name' => 'Anna Beispiel', 'email' => 'anna@example.com'])
            ->assertRedirect(route('profile'));

        $this->assertSame('Anna Beispiel', $this->user->fresh()?->name);
        $this->assertSame('Anna Beispiel', $this->idp->users['idp-1']->name);
        Notification::assertNothingSent();
    }

    public function test_the_name_stays_if_the_provider_fails(): void
    {
        $this->idp->failUpdates = true;

        $this->actingAs($this->user)->put(route('profile.update'), ['name' => 'Anna Beispiel', 'email' => 'anna@example.com'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Anna Muster', $this->user->fresh()?->name);
    }

    public function test_a_new_email_address_is_used_only_after_the_confirmation(): void
    {
        $this->actingAs($this->user)->put(route('profile.update'), ['name' => 'Anna Muster', 'email' => 'Anna@New.example.com'])
            ->assertRedirect(route('profile'));

        $this->assertSame('anna@example.com', $this->storedEmail());
        Notification::assertSentTo(new AnonymousNotifiable, ConfirmEmailChange::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'anna@new.example.com');

        // Opening the link does not change anything yet (mail scanners).
        $link = $this->emailLink('anna@new.example.com');
        $this->get($link)->assertOk()->assertSee('anna@new.example.com');
        $this->assertSame('anna@example.com', $this->storedEmail());

        $this->post($link)->assertRedirect(route('profile'));

        $this->assertSame('anna@new.example.com', $this->storedEmail());
        $this->assertSame('anna@new.example.com', $this->idp->users['idp-1']->email);
        $this->assertTrue($this->idp->verified['idp-1']);
    }

    public function test_tampered_outdated_or_taken_links_change_nothing(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post($this->emailLink('anna@new.example.com').'x')->assertForbidden();
        $this->post($this->emailLink('anna@new.example.com', from: 'old@example.com'))->assertOk()->assertSee('Address not changed');
        $this->post($this->emailLink('taken@example.com'))->assertOk()->assertSee('Address not changed');

        $this->idp->failUpdates = true;
        $this->post($this->emailLink('anna@new.example.com'))->assertOk()->assertSee('Address not changed');

        $this->assertSame('anna@example.com', $this->storedEmail());
    }
}

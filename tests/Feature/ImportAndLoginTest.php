<?php

namespace Tests\Feature;

use App\Auth\OidcLabel;
use App\Enums\AccountStatus;
use App\Identity\IdentityUser;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportAndLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function test_the_import_links_provider_users_and_their_service_groups(): void
    {
        $idp = $this->fakeIdentityProvider();
        $idp->users['u1'] = new IdentityUser('u1', 'Anna@Example.com', 'Anna', groups: ['svc-recipes', 'family']);
        $idp->users['u2'] = new IdentityUser('u2', null, 'No Mail');
        $idp->groups['u1'] = ['family', 'svc-recipes'];
        $existing = User::factory()->create(['email' => 'anna@example.com']);
        $recipes = Service::factory()->create(['access_group' => 'svc-recipes']);

        $this->command('accounts:import', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($existing->fresh()->oidc_sub);

        $this->command('accounts:import')->assertSuccessful();

        $this->assertSame('u1', $existing->fresh()->oidc_sub);
        $this->assertSame(AccountStatus::Active, $existing->serviceAccounts()->where('service_id', $recipes->id)->firstOrFail()->status);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(['family', 'svc-recipes'], $idp->groups['u1']);
    }

    public function test_the_import_needs_a_managed_provider(): void
    {
        $this->fakeIdentityProvider(manages: false);

        $this->command('accounts:import')->assertFailed();
    }

    public function test_make_admin_creates_or_promotes_a_user(): void
    {
        $this->command('accounts:make-admin', ['email' => 'Admin@Example.com'])->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'admin@example.com')->firstOrFail()->is_admin);
    }

    public function test_an_oidc_login_signs_in_and_links_by_verified_email(): void
    {
        config(['login.oidc.enabled' => true]);
        $user = User::factory()->create(['email' => 'anna@example.com']);

        $identity = (new SocialiteUser)->setRaw(['sub' => 'u1', 'email' => 'anna@example.com', 'email_verified' => true])
            ->map(['id' => 'u1', 'email' => 'anna@example.com', 'name' => 'Anna']);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        $this->get(route('oidc.callback'))->assertRedirect(route('portal'))
            // Stays signed in beyond the session lifetime.
            ->assertCookie(Auth::guard()->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertSame('u1', $user->fresh()->oidc_sub);
    }

    #[DataProvider('adminReturnCases')]
    public function test_the_admin_login_returns_admins_to_the_panel(bool $admin, string $target): void
    {
        config(['login.oidc.enabled' => true]);
        User::factory()->create(['email' => 'anna@example.com', 'is_admin' => $admin]);

        $identity = (new SocialiteUser)->setRaw(['sub' => 'u1', 'email' => 'anna@example.com', 'email_verified' => true])
            ->map(['id' => 'u1', 'email' => 'anna@example.com', 'name' => 'Anna']);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://login.example.com/authorize'));
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        $this->get(route('oidc.redirect', ['panel' => 'admin']))->assertRedirect('https://login.example.com/authorize');
        $this->get(route('oidc.callback'))->assertRedirect($target === 'admin' ? Filament::getPanel('admin')->getUrl() : route('portal'));
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function adminReturnCases(): array
    {
        return [
            'admin' => [true, 'admin'],
            'no admin' => [false, 'portal'],
        ];
    }

    public function test_the_login_page_offers_the_provider(): void
    {
        config(['login.oidc.enabled' => true, 'login.oidc.label' => 'Pocket ID']);

        $this->get(route('login'))->assertOk()->assertSee('Pocket ID')->assertSee(route('oidc.redirect'), false);
    }

    public function test_the_admin_login_shows_the_provider_above_the_password_form(): void
    {
        config(['login.oidc.enabled' => true, 'login.oidc.label' => 'Pocket ID']);

        $this->get(route('filament.admin.auth.login'))->assertOk()
            ->assertSeeInOrder([route('oidc.redirect'), 'type="password"'], false);
    }

    public function test_a_regional_locale_uses_the_base_language(): void
    {
        config(['login.oidc.enabled' => true, 'login.oidc.label' => 'Pocket ID']);
        app()->setLocale('de_CH');

        $this->assertSame('de', app()->getLocale());
        $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('Anmelden mit Pocket ID');
    }

    public function test_the_login_page_names_the_provider_as_pocket_id_publishes_it(): void
    {
        config(['login.oidc.enabled' => true, 'login.oidc.label' => null, 'services.oidc.base_url' => 'https://login.example.com']);
        Http::fake(['login.example.com/api/application-configuration' => Http::response([
            ['key' => 'appName', 'type' => '', 'value' => 'NarrenLogin'],
        ])]);

        $this->get(route('login'))->assertOk()->assertSee('NarrenLogin');
        $this->assertSame('NarrenLogin', OidcLabel::get());
        Http::assertSentCount(1);
    }

    public function test_the_label_is_sso_when_the_provider_publishes_no_name(): void
    {
        config(['login.oidc.label' => null, 'services.oidc.base_url' => 'https://login.example.com']);
        Http::fake(['*' => Http::response('Not found', 404)]);

        $this->assertSame(OidcLabel::FALLBACK, OidcLabel::get());
    }
}

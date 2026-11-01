<?php

namespace Tests\Feature;

use App\Accounts\Access;
use App\Enums\AccountStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\ServiceAccountsRelationManager;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\AccessGranted;
use App\Notifications\AccessRequested;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('portal'))->assertRedirect(route('login'));
    }

    public function test_the_portal_lists_the_visible_services_with_the_status(): void
    {
        $user = User::factory()->create();
        $active = Service::factory()->create(['name' => 'Rezepte']);
        Service::factory()->create(['name' => 'Hidden', 'visible' => false]);
        ServiceAccount::factory()->for($user)->for($active)->create();

        $this->actingAs($user)->get(route('portal'))
            ->assertOk()
            ->assertSee('Rezepte')
            ->assertSee('Active')
            ->assertDontSee('Hidden');
    }

    public function test_open_starts_the_login_of_the_service_if_known(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['url' => 'https://recipes.example.com', 'login_url' => 'https://recipes.example.com/auth/oidc/redirect']);
        ServiceAccount::factory()->for($user)->for($service)->create();

        $this->actingAs($user)->get(route('portal'))->assertSee('https://recipes.example.com/auth/oidc/redirect', false);
    }

    public function test_services_with_self_registration_are_activated_right_away(): void
    {
        $idp = $this->fakeIdentityProvider();
        $user = User::factory()->create(['oidc_sub' => 'idp-9']);
        $service = Service::factory()->selfSignup()->create(['access_group' => 'svc-tasks']);

        $this->actingAs($user)->post(route('services.claim', $service))->assertRedirect(route('portal'));

        $this->assertSame(AccountStatus::Active, $user->serviceAccounts()->firstOrFail()->status);
        $this->assertSame(['svc-tasks'], $idp->groups['idp-9']);
    }

    public function test_other_services_are_requested_from_the_admins(): void
    {
        $this->fakeIdentityProvider();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs($user)->post(route('services.claim', $service));

        $this->assertSame(AccountStatus::Requested, $user->serviceAccounts()->firstOrFail()->status);
        Notification::assertSentTo($admin, AccessRequested::class);
    }

    public function test_suspended_accounts_cannot_be_reactivated_by_the_user(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->selfSignup()->create();
        ServiceAccount::factory()->for($user)->for($service)->create(['status' => AccountStatus::Suspended]);

        $this->actingAs($user)->post(route('services.claim', $service));

        $this->assertSame(AccountStatus::Suspended, $user->serviceAccounts()->firstOrFail()->status);
    }

    public function test_only_admins_can_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())->get(Filament::getPanel('admin')->getUrl())->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(Filament::getPanel('admin')->getUrl())->assertOk();
    }

    public function test_approving_a_request_grants_the_group_and_notifies_the_user(): void
    {
        $idp = $this->fakeIdentityProvider();
        $user = User::factory()->create(['oidc_sub' => 'idp-5']);
        $idp->groups['idp-5'] = ['family'];
        $account = ServiceAccount::factory()->for($user)->for(Service::factory()->create(['access_group' => 'svc-cloud']))->create(['status' => AccountStatus::Requested]);

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel('admin');

        Livewire::test(ServiceAccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->callAction(TestAction::make('approve')->table($account));

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        // Groups the AccountManager does not manage stay.
        $this->assertSame(['family', 'svc-cloud'], $idp->groups['idp-5']);
        Notification::assertSentTo($user, AccessGranted::class);

        app(Access::class)->suspend($account->fresh());
        $this->assertSame(['family'], $idp->groups['idp-5']);
    }
}

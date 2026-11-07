<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\ConnectorRuns\Pages\ListConnectorRuns;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\ServiceAccountsRelationManager;
use App\Jobs\RunConnector;
use App\Models\ConnectorRun;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectorAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_admins_configure_the_wizarr_connector_of_a_service(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Emby', 'key' => 'emby', 'type' => ServiceType::External, 'connector' => 'wizarr'])
            ->call('create')
            ->assertHasFormErrors(['connector_config.base_url' => 'required', 'connector_config.server_id' => 'required', 'wizarr_api_key' => 'required']);

        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Emby',
                'key' => 'emby',
                'type' => ServiceType::External,
                'connector' => 'wizarr',
                'connector_config.base_url' => 'http://wizarr:5690',
                'connector_config.server_id' => '1',
                'connector_config.library_ids' => ['2', '3'],
                'connector_config.expires_in_days' => '7',
                'wizarr_api_key' => 'first-key',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $emby = Service::query()->where('key', 'emby')->firstOrFail();
        $this->assertSame('wizarr', $emby->connector);
        $this->assertSame('first-key', $emby->connectorSetting('api_key'));
        $this->assertSame(['2', '3'], $emby->connectorSetting('library_ids'));

        // The key never goes to the browser and stays when the field is left empty.
        $edit = Livewire::test(EditService::class, ['record' => $emby->getRouteKey()]);
        $this->assertArrayNotHasKey('api_key', $edit->get('data.connector_config'));
        $edit->assertDontSee('first-key')
            ->fillForm(['connector_config.public_url' => 'https://invite.example.com'])
            ->call('save')
            ->assertHasNoFormErrors();
        $emby->refresh();
        $this->assertSame('first-key', $emby->connectorSetting('api_key'));
        $this->assertSame('https://invite.example.com', $emby->connectorSetting('public_url'));

        Livewire::test(EditService::class, ['record' => $emby->getRouteKey()])
            ->fillForm(['wizarr_api_key' => 'second-key'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('second-key', $emby->refresh()->connectorSetting('api_key'));

        // Switching to manual keeps only what the manual connector needs.
        Livewire::test(EditService::class, ['record' => $emby->getRouteKey()])
            ->fillForm(['connector' => 'manual', 'connector_config.instructions' => 'Log in at emby.example.com'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['instructions' => 'Log in at emby.example.com'], $emby->refresh()->connector_config);
    }

    public function test_services_keep_the_default_connector(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Recipes', 'key' => 'recipes', 'access_group' => 'svc-recipes'])
            ->call('create')
            ->assertHasNoFormErrors();

        $recipes = Service::query()->where('key', 'recipes')->firstOrFail();
        $this->assertSame('oidc-group', $recipes->connector);
        $this->assertNull($recipes->connector_config);
    }

    public function test_the_user_page_shows_the_connector_state_log_and_retry(): void
    {
        Queue::fake();
        $emby = Service::factory()->create(['name' => 'Emby', 'connector' => 'wizarr', 'access_group' => null, 'connector_config' => ['base_url' => 'http://wizarr:5690', 'api_key' => 'k', 'server_id' => 1]]);
        $user = User::factory()->create();
        $account = ServiceAccount::factory()->for($user)->for($emby)->create([
            'status' => AccountStatus::Active,
            'connector_data' => ['state' => 'invited', 'invitation_id' => 5, 'invitation_url' => 'https://invite.example.com/j/ABC'],
        ]);
        ConnectorRun::query()->firstOrFail()->update(['status' => ConnectorRunStatus::Failed, 'message' => 'Wizarr is not reachable: timeout']);

        $manager = fn () => Livewire::test(ServiceAccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);

        $manager()->assertOk()->assertSee('Invitation open');

        $manager()->mountAction(TestAction::make('connectorLog')->table($account))
            ->assertMountedActionModalSee(['Provision', 'Wizarr is not reachable: timeout']);

        $manager()->callAction(TestAction::make('connectorRetry')->table($account));
        $this->assertSame(2, ConnectorRun::query()->where('action', ConnectorAction::Provision)->count());
        Queue::assertPushed(RunConnector::class, 2);

        // A redeemed invitation that could not be matched: the admin enters the Wizarr user id.
        $manager()->callAction(TestAction::make('edit')->table($account), ['external_id' => '12'])->assertHasNoFormErrors();
        $this->assertSame('12', $account->fresh()?->external_id);
    }

    public function test_accounts_of_services_with_the_default_connector_have_no_connector_actions(): void
    {
        $user = User::factory()->create();
        $account = ServiceAccount::factory()->for($user)->for(Service::factory()->create())->create();

        Livewire::test(ServiceAccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->assertActionHidden(TestAction::make('connectorRetry')->table($account))
            ->assertActionHidden(TestAction::make('connectorLog')->table($account));
    }

    public function test_failed_runs_are_retried_from_the_connector_log(): void
    {
        Queue::fake();
        $service = Service::factory()->create(['name' => 'Minecraft', 'connector' => 'manual']);
        ServiceAccount::factory()->for($service)->create(['status' => AccountStatus::Active]);
        $run = ConnectorRun::query()->firstOrFail();
        $run->update(['status' => ConnectorRunStatus::Failed, 'message' => 'Boom']);

        Livewire::test(ListConnectorRuns::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$run])
            ->assertSee('Boom')
            ->callAction(TestAction::make('retry')->table($run));

        $this->assertSame(ConnectorRunStatus::Pending, $run->fresh()?->status);
        Queue::assertPushed(RunConnector::class, 2);
    }
}

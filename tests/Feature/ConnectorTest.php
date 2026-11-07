<?php

namespace Tests\Feature;

use App\Accounts\Access;
use App\Connectors\ConnectorException;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Connectors;
use App\Connectors\ManualConnector;
use App\Connectors\OidcGroupConnector;
use App\Connectors\WizarrConnector;
use App\Enums\AccountStatus;
use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use App\Enums\ServiceType;
use App\Jobs\RunConnector;
use App\Jobs\SyncUserGroups;
use App\Models\ConnectorRun;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\ConnectorFailed;
use App\Notifications\ManualSetupNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

class ConnectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function manualService(): Service
    {
        return Service::factory()->create([
            'key' => 'minecraft',
            'name' => 'Minecraft',
            'type' => ServiceType::External,
            'access_group' => null,
            'connector' => 'manual',
            'connector_config' => ['instructions' => "Server: mc.example.com\nWhitelist on request."],
        ]);
    }

    /**
     * @return list<string>
     */
    private function runs(): array
    {
        return array_values(ConnectorRun::query()->orderBy('id')->get()->map(fn (ConnectorRun $run): string => $run->action->value)->all());
    }

    public function test_the_registry_resolves_the_connector_of_a_service(): void
    {
        $registry = app(ConnectorRegistry::class);
        $service = Service::factory()->create();

        $this->assertSame(ConnectorRegistry::DEFAULT, $service->fresh()?->connector);
        $this->assertInstanceOf(OidcGroupConnector::class, $registry->for($service));
        $this->assertInstanceOf(WizarrConnector::class, $registry->connector('wizarr'));
        $this->assertInstanceOf(ManualConnector::class, $registry->connector('manual'));
        $this->assertSame(['oidc-group', 'wizarr', 'manual'], array_keys($registry->options()));
        $this->assertTrue($registry->isPassive('oidc-group'));
        $this->assertFalse($registry->isPassive('wizarr'));

        $registry->extend('custom', ManualConnector::class);
        $this->assertInstanceOf(ManualConnector::class, $registry->connector('custom'));

        try {
            $registry->connector('unknown');
            $this->fail('Unknown connectors must throw.');
        } catch (ConnectorException $exception) {
            $this->assertTrue($exception->permanent);
        }
    }

    public function test_the_connector_config_is_stored_encrypted_and_hidden(): void
    {
        $service = Service::factory()->create(['connector' => 'wizarr', 'connector_config' => ['base_url' => 'https://wizarr.test', 'api_key' => 'secret-key']]);

        $raw = (string) Service::query()->toBase()->where('id', $service->id)->value('connector_config');
        $this->assertStringNotContainsString('secret-key', $raw);
        $this->assertSame('secret-key', $service->fresh()?->connectorSetting('api_key'));
        $this->assertArrayNotHasKey('connector_config', $service->toArray());
    }

    public function test_services_with_the_default_connector_only_sync_the_groups(): void
    {
        Queue::fake();
        $service = Service::factory()->create();
        $user = User::factory()->create(['oidc_sub' => 'sub-anna']);
        $account = ServiceAccount::factory()->for($user)->for($service)->create(['status' => AccountStatus::Requested]);

        app(Access::class)->approve($account);
        app(Access::class)->suspend($account, 'Test');
        app(Access::class)->reactivate($account);
        $account->delete();
        $user->delete();

        Queue::assertPushed(SyncUserGroups::class);
        Queue::assertNotPushed(RunConnector::class);
        $this->assertSame(0, ConnectorRun::query()->count());
    }

    public function test_the_access_lifecycle_dispatches_the_connector_calls(): void
    {
        Queue::fake();
        $service = $this->manualService();
        $user = User::factory()->create();
        $account = ServiceAccount::factory()->for($user)->for($service)->create(['status' => AccountStatus::Requested]);
        $this->assertSame([], $this->runs());

        app(Access::class)->approve($account);
        app(Access::class)->suspend($account, 'Unpaid', $this->admin);
        app(Access::class)->reactivate($account, null, $this->admin);
        $account->update(['note' => 'no status change']);
        $account->delete();

        $this->assertSame(['provision', 'suspend', 'resume', 'deprovision'], $this->runs());
        Queue::assertPushed(RunConnector::class, 4);

        $deprovision = ConnectorRun::query()->latest('id')->firstOrFail();
        $this->assertNull($deprovision->service_account_id);
        $this->assertSame($user->id, $deprovision->user_id);
        $this->assertSame($user->email, $deprovision->snapshot['email'] ?? null);
        $this->assertSame(ConnectorRunStatus::Pending, $deprovision->status);
    }

    public function test_created_accounts_are_provisioned_and_deleted_users_deprovisioned(): void
    {
        Queue::fake();
        $service = $this->manualService();
        $other = Service::factory()->create(['connector' => 'manual']);
        $user = User::factory()->create();

        ServiceAccount::factory()->for($user)->for($service)->create(['status' => AccountStatus::Active]);
        ServiceAccount::factory()->for($user)->for($other)->create(['status' => AccountStatus::Requested]);
        $this->assertSame(['provision'], $this->runs());

        $user->delete();

        // Only the account that was provisioned; requested accounts never were.
        $this->assertSame(['provision', 'deprovision'], $this->runs());
        $run = ConnectorRun::query()->latest('id')->firstOrFail();
        $this->assertNull($run->user_id);
        $this->assertSame($user->name, $run->snapshot['name'] ?? null);
        $this->assertSame($user->email.'', $run->snapshot['email'] ?? null);
    }

    public function test_requested_accounts_that_get_suspended_are_not_suspended_in_the_service(): void
    {
        Queue::fake();
        $account = ServiceAccount::factory()->for($this->manualService())->create(['status' => AccountStatus::Requested]);

        $account->update(['status' => AccountStatus::Suspended]);

        $this->assertSame([], $this->runs());
    }

    public function test_the_manual_connector_mails_the_admins_what_to_do(): void
    {
        $service = $this->manualService();
        $user = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $account = ServiceAccount::factory()->for($user)->for($service)->create(['status' => AccountStatus::Requested, 'external_username' => 'anna_mc']);

        app(Access::class)->approve($account);

        $run = ConnectorRun::query()->firstOrFail();
        $this->assertSame(ConnectorRunStatus::Manual, $run->status);
        $this->assertSame(1, $run->attempts);
        Notification::assertSentTo($this->admin, ManualSetupNeeded::class, function (ManualSetupNeeded $notification): bool {
            $mail = $notification->toMail($this->admin);

            return $notification->action === ConnectorAction::Provision
                && $mail->subject === 'Create account: Minecraft'
                && str_contains(implode(' ', $mail->introLines), 'Anna (anna@example.com) has access to Minecraft now.')
                && $mail->actionUrl !== null;
        });

        app(Access::class)->suspend($account, 'Test');
        Notification::assertSentTo($this->admin, ManualSetupNeeded::class, fn (ManualSetupNeeded $notification): bool => $notification->action === ConnectorAction::Suspend
            && str_contains(implode(' ', $notification->toMail($this->admin)->introLines), 'username: anna_mc'));

        // After the user is deleted the mail still has the names, but no link to the user.
        $user->delete();
        Notification::assertSentTo($this->admin, ManualSetupNeeded::class, function (ManualSetupNeeded $notification): bool {
            $mail = $notification->toMail($this->admin);

            return $notification->action === ConnectorAction::Deprovision
                && $mail->subject === 'Delete account: Minecraft'
                && str_contains(implode(' ', $mail->introLines), 'Anna (anna@example.com)')
                && $mail->actionUrl === null;
        });
    }

    public function test_the_portal_shows_the_instructions_of_a_manual_service(): void
    {
        $service = $this->manualService();
        $user = User::factory()->create();
        ServiceAccount::factory()->for($user)->for($service)->create(['status' => AccountStatus::Active]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('How to log in')
            ->assertSee('Server: mc.example.com');
    }

    public function test_outdated_runs_are_skipped(): void
    {
        Queue::fake();
        $account = ServiceAccount::factory()->for($this->manualService())->create(['status' => AccountStatus::Active]);
        $provision = ConnectorRun::query()->firstOrFail();

        // Suspended before the provisioning ran (e.g. a retry hours later).
        $account->update(['status' => AccountStatus::Suspended]);
        app(Connectors::class)->execute($provision);

        $this->assertSame(ConnectorRunStatus::Skipped, $provision->fresh()?->status);
        Notification::assertNotSentTo($this->admin, ManualSetupNeeded::class);
    }

    public function test_failed_runs_notify_the_admins_and_can_be_retried(): void
    {
        Queue::fake();
        $account = ServiceAccount::factory()->for($this->manualService())->create(['status' => AccountStatus::Active]);
        $run = ConnectorRun::query()->firstOrFail();

        (new RunConnector($run))->failed(new RuntimeException('Boom'));

        $run->refresh();
        $this->assertSame(ConnectorRunStatus::Failed, $run->status);
        $this->assertSame('Boom', $run->message);
        Notification::assertSentTo($this->admin, ConnectorFailed::class, function (ConnectorFailed $notification) use ($account): bool {
            $mail = $notification->toMail($this->admin);

            return $mail->subject === 'Connector failed: Minecraft'
                && str_contains(implode(' ', $mail->introLines), $account->user->email);
        });

        app(Connectors::class)->retry($run);
        $this->assertSame(ConnectorRunStatus::Pending, $run->fresh()?->status);
        Queue::assertPushed(RunConnector::class, 2);
    }

    public function test_unknown_connectors_fail_without_retries(): void
    {
        Queue::fake();
        ServiceAccount::factory()->for($this->manualService())->create(['status' => AccountStatus::Active]);
        $run = ConnectorRun::query()->firstOrFail();
        $run->update(['connector' => 'gone']);

        $job = (new RunConnector($run))->withFakeQueueInteractions();
        $job->handle(app(Connectors::class));

        $job->assertFailed();
        $this->assertSame('Unknown connector "gone".', $run->fresh()?->message);
    }

    public function test_the_retry_of_an_account_runs_the_step_of_its_status(): void
    {
        Queue::fake();
        $account = ServiceAccount::factory()->for($this->manualService())->create(['status' => AccountStatus::Active]);

        app(Connectors::class)->resync($account);
        $account->update(['status' => AccountStatus::Suspended]);
        app(Connectors::class)->resync($account);
        $account->update(['status' => AccountStatus::Requested]);
        $this->assertNull(app(Connectors::class)->resync($account));

        $this->assertSame(['provision', 'provision', 'suspend', 'suspend'], $this->runs());
    }

    public function test_old_runs_are_pruned_but_failed_runs_kept(): void
    {
        Queue::fake();
        $account = ServiceAccount::factory()->for($this->manualService())->create(['status' => AccountStatus::Active]);
        $old = ConnectorRun::query()->firstOrFail();
        $old->forceFill(['status' => ConnectorRunStatus::Succeeded, 'created_at' => now()->subDays(91)])->save();
        $failed = app(Connectors::class)->resync($account);
        $failed?->forceFill(['status' => ConnectorRunStatus::Failed, 'created_at' => now()->subDays(91)])->save();
        $recent = app(Connectors::class)->resync($account);

        $command = $this->artisan('connectors:prune');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        $this->assertModelMissing($old);
        $this->assertNotNull($failed?->fresh());
        $this->assertNotNull($recent?->fresh());
    }
}

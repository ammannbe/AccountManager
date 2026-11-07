<?php

namespace Tests\Feature;

use App\Accounts\Access;
use App\Connectors\ConnectorException;
use App\Connectors\Connectors;
use App\Enums\AccountStatus;
use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use App\Enums\ServiceType;
use App\Jobs\RunConnector;
use App\Models\ConnectorRun;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\AccessGranted;
use App\Notifications\ConnectorFailed;
use App\Notifications\ExternalInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * The payloads follow the Flask-RESTX models of Wizarr v2026.10 (app/blueprints/api/models.py and
 * api_routes.py); see docs/wizarr.md for what to verify against the installed version.
 */
class WizarrConnectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $emby;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->anna = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $this->emby = Service::factory()->create([
            'key' => 'emby',
            'name' => 'Emby',
            'url' => 'https://emby.example.com',
            'type' => ServiceType::External,
            'access_group' => null,
            'requires_approval' => true,
            'connector' => 'wizarr',
            'connector_config' => [
                'base_url' => 'http://wizarr:5690',
                'public_url' => 'https://invite.example.com',
                'api_key' => 'test-api-key',
                'server_id' => '1',
                'library_ids' => ['2', '3'],
                'expires_in_days' => '7',
                'allow_downloads' => true,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invitation(int $id = 5, string $code = 'ABCDEF1234', string $status = 'pending', ?string $usedBy = null): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'url' => "/j/{$code}",
            'status' => $status,
            'created' => '2026-11-07T12:00:00',
            'expires' => '2026-11-14T12:00:00',
            'used_at' => $status === 'used' ? '2026-11-08T09:30:00' : null,
            'used_by' => $usedBy,
            'duration' => 'unlimited',
            'unlimited' => false,
            'specific_libraries' => [2, 3],
            'display_name' => 'Emby',
            'server_names' => ['Emby'],
            'uses_global_setting' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wizarrUser(int $id = 12, string $username = 'anna', ?string $email = 'anna@example.com'): array
    {
        return ['id' => $id, 'username' => $username, 'email' => $email, 'server' => 'Emby', 'server_type' => 'emby', 'expires' => null, 'created_at' => '2026-11-08T09:30:00'];
    }

    /**
     * An account with an open invitation, as provisioning leaves it.
     */
    private function invitedAccount(AccountStatus $status = AccountStatus::Active): ServiceAccount
    {
        return ServiceAccount::withoutEvents(fn (): ServiceAccount => ServiceAccount::factory()->for($this->anna)->for($this->emby)->create([
            'status' => $status,
            'connector_data' => [
                'state' => 'invited',
                'invitation_id' => 5,
                'invitation_code' => 'ABCDEF1234',
                'invitation_url' => 'https://invite.example.com/j/ABCDEF1234',
                'invitation_expires_at' => now()->addDays(7)->toIso8601String(),
            ],
        ]));
    }

    private function redeemedAccount(AccountStatus $status = AccountStatus::Active): ServiceAccount
    {
        $account = $this->invitedAccount($status);
        $account->forceFill(['external_id' => '12', 'external_username' => 'anna', 'connector_data' => ['state' => 'active']])->saveQuietly();

        return $account->fresh() ?? $account;
    }

    private function check(): void
    {
        $command = $this->artisan('connectors:check');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
    }

    private function data(ServiceAccount $account, string $key): mixed
    {
        return ($account->fresh()->connector_data ?? [])[$key] ?? null;
    }

    private function lastRun(): ConnectorRun
    {
        return ConnectorRun::query()->latest('id')->firstOrFail();
    }

    public function test_approving_creates_a_single_use_invitation_and_mails_it(): void
    {
        Http::fake(['wizarr:5690/api/invitations' => Http::response(['message' => 'Invitation created successfully', 'invitation' => $this->invitation()], 201)]);
        $account = ServiceAccount::factory()->for($this->anna)->for($this->emby)->create(['status' => AccountStatus::Requested]);

        app(Access::class)->approve($account);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://wizarr:5690/api/invitations'
            && $request->header('X-API-Key') === ['test-api-key']
            && $request->data() === [
                'server_ids' => [1],
                'expires_in_days' => 7,
                'duration' => 'unlimited',
                'unlimited' => false,
                'library_ids' => [2, 3],
                'allow_downloads' => true,
                'allow_live_tv' => false,
            ]);

        $account->refresh();
        $this->assertSame('invited', $account->connector_data['state'] ?? null);
        $this->assertSame(5, $account->connector_data['invitation_id'] ?? null);
        $this->assertSame('https://invite.example.com/j/ABCDEF1234', $account->connector_data['invitation_url'] ?? null);
        $this->assertSame('2026-11-14T12:00:00+00:00', $account->connector_data['invitation_expires_at'] ?? null);
        $this->assertSame(ConnectorRunStatus::Succeeded, $this->lastRun()->status);
        $this->assertSame(ConnectorAction::Provision, $this->lastRun()->action);

        Notification::assertSentTo($this->anna, AccessGranted::class, fn (AccessGranted $notification): bool => $notification->toMail($this->anna)->actionUrl === null);
        Notification::assertSentTo($this->anna, ExternalInvitation::class, function (ExternalInvitation $notification): bool {
            $mail = $notification->toMail($this->anna);

            return $mail->subject === 'Your invitation to Emby' && $mail->actionUrl === 'https://invite.example.com/j/ABCDEF1234';
        });
    }

    public function test_the_portal_shows_the_open_invitation(): void
    {
        $this->invitedAccount();

        $this->actingAs($this->anna)->get('/')
            ->assertOk()
            ->assertSee('Invitation open')
            ->assertSee('https://invite.example.com/j/ABCDEF1234')
            ->assertSee('Accept invitation')
            ->assertDontSee('Open Emby');
    }

    public function test_retrying_an_open_invitation_mails_it_again(): void
    {
        Http::fake();
        $account = $this->invitedAccount();

        app(Connectors::class)->resync($account);

        Http::assertNothingSent();
        Notification::assertSentToTimes($this->anna, ExternalInvitation::class, 1);
        $this->assertStringContainsString('still open', (string) $this->lastRun()->message);
    }

    public function test_the_check_finds_the_redeemed_invitation(): void
    {
        Http::fake([
            'wizarr:5690/api/invitations' => Http::response(['invitations' => [$this->invitation(4, 'OTHER00001'), $this->invitation(5, 'ABCDEF1234', 'used', '<User 12>')], 'count' => 2]),
            'wizarr:5690/api/users' => Http::response(['users' => [$this->wizarrUser(11, 'bob', 'bob@example.com'), $this->wizarrUser(12, 'anna_emby', null)], 'count' => 2]),
        ]);
        $account = $this->invitedAccount();

        $this->check();

        $account->refresh();
        $this->assertSame('12', $account->external_id);
        $this->assertSame('anna_emby', $account->external_username);
        $this->assertSame('active', $account->connector_data['state'] ?? null);
        $this->assertArrayNotHasKey('invitation_url', $account->connector_data ?? []);
        $this->assertSame(ConnectorAction::Check, $this->lastRun()->action);
        $this->assertStringContainsString('redeemed by Wizarr user 12', (string) $this->lastRun()->message);

        $this->actingAs($this->anna)->get('/')->assertSee('Account created')->assertSee('Open Emby')->assertSee('Username: anna_emby');
    }

    public function test_the_check_matches_the_user_by_username_or_address(): void
    {
        Http::fake([
            'wizarr:5690/api/invitations' => Http::response(['invitations' => [$this->invitation(5, 'ABCDEF1234', 'used', null)], 'count' => 1]),
            'wizarr:5690/api/users' => Http::response(['users' => [$this->wizarrUser(11, 'bob', 'bob@example.com'), $this->wizarrUser(13, 'anna', 'Anna@Example.com')], 'count' => 2]),
        ]);
        $account = $this->invitedAccount();

        $this->check();

        $this->assertSame('13', $account->fresh()?->external_id);
    }

    public function test_pending_invitations_stay_and_expired_ones_are_shown(): void
    {
        Http::fake(['wizarr:5690/api/invitations' => Http::sequence()
            ->push(['invitations' => [$this->invitation()], 'count' => 1])
            ->push(['invitations' => [$this->invitation(5, 'ABCDEF1234', 'expired')], 'count' => 1]),
        ]);
        $account = $this->invitedAccount();

        $this->check();
        $this->assertSame('invited', $this->data($account, 'state'));
        $this->assertSame(0, ConnectorRun::query()->count());

        $this->check();
        $this->assertSame('expired', $this->data($account, 'state'));
        $this->assertStringContainsString('expired', (string) $this->lastRun()->message);
        Http::assertSentCount(2);

        $this->actingAs($this->anna)->get('/')->assertSee('Invitation expired')->assertSee('Ask an admin for a new invitation.');
    }

    public function test_used_invitations_without_a_matching_user_are_reported_once(): void
    {
        Http::fake([
            'wizarr:5690/api/invitations' => Http::response(['invitations' => [$this->invitation(5, 'ABCDEF1234', 'used', 'somebody')], 'count' => 1]),
            'wizarr:5690/api/users' => Http::response(['users' => [$this->wizarrUser(11, 'bob', 'bob@example.com')], 'count' => 1]),
        ]);
        $account = $this->invitedAccount();

        $this->check();
        $this->check();

        $this->assertNull($account->fresh()?->external_id);
        $this->assertSame(1, ConnectorRun::query()->where('status', ConnectorRunStatus::Failed)->count());
        Notification::assertSentToTimes($this->admin, ConnectorFailed::class, 1);
    }

    public function test_a_redemption_after_a_suspension_disables_the_user_right_away(): void
    {
        Http::fake([
            'wizarr:5690/api/invitations' => Http::response(['invitations' => [$this->invitation(5, 'ABCDEF1234', 'used', '<User 12>')], 'count' => 1]),
            'wizarr:5690/api/users' => Http::response(['users' => [$this->wizarrUser()], 'count' => 1]),
            'wizarr:5690/api/users/12/disable' => Http::response(['message' => 'User anna disabled successfully']),
        ]);
        // Suspended after the provisioning, but the invitation was not revoked in time.
        $account = $this->invitedAccount(AccountStatus::Suspended);

        $this->check();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://wizarr:5690/api/users/12/disable');
        $this->assertSame('suspended', $this->data($account, 'state'));
    }

    public function test_suspending_and_resuming_disables_and_enables_the_user(): void
    {
        Http::fake([
            'wizarr:5690/api/users/12/disable' => Http::response(['message' => 'User anna disabled successfully']),
            'wizarr:5690/api/users/12/enable' => Http::response(['message' => 'User anna enabled successfully']),
        ]);
        $account = $this->redeemedAccount();

        app(Access::class)->suspend($account, 'Unpaid', $this->admin);
        $this->assertSame('suspended', $this->data($account, 'state'));
        $this->assertSame('Wizarr: User anna disabled successfully', $this->lastRun()->message);

        app(Access::class)->reactivate($account, null, $this->admin);
        $this->assertSame('active', $this->data($account, 'state'));
        $this->assertSame(ConnectorAction::Resume, $this->lastRun()->action);
        $this->assertSame(ConnectorRunStatus::Succeeded, $this->lastRun()->status);

        Http::assertSentCount(2);
    }

    public function test_servers_that_cannot_disable_users_get_a_new_invitation_on_resume(): void
    {
        Http::fake([
            'wizarr:5690/api/users/12/disable' => Http::response(['message' => 'User anna deleted (disable not supported by server)']),
            'wizarr:5690/api/invitations' => Http::response(['message' => 'Invitation created successfully', 'invitation' => $this->invitation(6, 'NEWCODE001')], 201),
        ]);
        $account = $this->redeemedAccount();

        app(Access::class)->suspend($account, 'Unpaid', $this->admin);
        $account->refresh();
        $this->assertNull($account->external_id);
        $this->assertSame('removed', $account->connector_data['state'] ?? null);

        app(Access::class)->reactivate($account, null, $this->admin);
        $this->assertSame('https://invite.example.com/j/NEWCODE001', $this->data($account, 'invitation_url'));
        Notification::assertSentTo($this->anna, ExternalInvitation::class);
    }

    public function test_suspending_revokes_an_open_invitation_and_resuming_invites_again(): void
    {
        Http::fake([
            'wizarr:5690/api/invitations/5' => Http::response(['message' => 'Invitation ABCDEF1234 deleted successfully']),
            'wizarr:5690/api/invitations' => Http::response(['message' => 'Invitation created successfully', 'invitation' => $this->invitation(6, 'NEWCODE001')], 201),
        ]);
        $account = $this->invitedAccount();

        app(Access::class)->suspend($account, 'Test', $this->admin);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://wizarr:5690/api/invitations/5');
        $this->assertSame('revoked', $this->data($account, 'state'));

        app(Access::class)->reactivate($account, null, $this->admin);
        $this->assertSame(6, $this->data($account, 'invitation_id'));
    }

    public function test_resuming_fails_for_good_if_wizarr_cannot_enable_the_user(): void
    {
        Http::fake(['wizarr:5690/api/users/12/enable' => Http::response(['message' => 'Enable failed or not supported for user anna'])]);
        $account = $this->redeemedAccount(AccountStatus::Active);
        Queue::fake();
        $run = app(Connectors::class)->dispatch($account, ConnectorAction::Resume);
        $this->assertNotNull($run);

        $job = (new RunConnector($run))->withFakeQueueInteractions();
        $job->handle(app(Connectors::class));

        $job->assertFailed();
        $this->assertStringContainsString('could not enable', (string) $run->fresh()?->message);
    }

    public function test_removing_the_access_deletes_the_user(): void
    {
        Http::fake(['wizarr:5690/api/users/12' => Http::response(['message' => 'User anna deleted successfully'])]);
        $account = $this->redeemedAccount();

        $account->delete();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://wizarr:5690/api/users/12');
        $run = $this->lastRun();
        $this->assertSame(ConnectorAction::Deprovision, $run->action);
        $this->assertSame(ConnectorRunStatus::Succeeded, $run->status);
        $this->assertNull($run->service_account_id);
    }

    public function test_deleting_the_user_revokes_the_open_invitation(): void
    {
        // Already deleted in Wizarr by hand: fine as well.
        Http::fake(['wizarr:5690/api/invitations/5' => Http::response(['error' => 'Invitation not found'], 404)]);
        $this->invitedAccount();

        $this->anna->delete();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://wizarr:5690/api/invitations/5');
        $this->assertSame(ConnectorRunStatus::Succeeded, $this->lastRun()->status);
        $this->assertStringContainsString('already gone', (string) $this->lastRun()->message);
    }

    public function test_server_errors_are_retried_and_the_admins_notified_after_the_last_attempt(): void
    {
        Http::fake(['wizarr:5690/api/invitations' => Http::response(['error' => 'Internal server error'], 500)]);
        Queue::fake();
        $account = ServiceAccount::factory()->for($this->anna)->for($this->emby)->create(['status' => AccountStatus::Active]);
        $run = $this->lastRun();

        $job = (new RunConnector($run))->withFakeQueueInteractions();

        try {
            $job->handle(app(Connectors::class));
            $this->fail('Server errors must be retried.');
        } catch (ConnectorException $exception) {
            $this->assertFalse($exception->permanent);
        }

        $job->assertNotFailed();
        $run->refresh();
        $this->assertSame(ConnectorRunStatus::Pending, $run->status);
        $this->assertStringContainsString('HTTP 500 Internal server error', (string) $run->message);
        $this->assertSame(6, $job->tries);
        Notification::assertNotSentTo($this->admin, ConnectorFailed::class);

        // After the last attempt the queue calls failed().
        $job->failed($exception);
        $this->assertSame(ConnectorRunStatus::Failed, $run->refresh()->status);
        Notification::assertSentTo($this->admin, ConnectorFailed::class);
        $this->assertNull($account->fresh()?->connector_data);
    }

    public function test_a_rejected_api_key_fails_right_away(): void
    {
        Http::fake(['wizarr:5690/api/invitations' => Http::response(['error' => 'Unauthorized'], 401)]);
        Queue::fake();
        ServiceAccount::factory()->for($this->anna)->for($this->emby)->create(['status' => AccountStatus::Active]);
        $run = $this->lastRun();

        $job = (new RunConnector($run))->withFakeQueueInteractions();
        $job->handle(app(Connectors::class));

        $job->assertFailed();
        $this->assertStringContainsString('HTTP 401 Unauthorized', (string) $run->fresh()?->message);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['wizarr:5690/api/invitations' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/login'])]);
        Queue::fake();
        ServiceAccount::factory()->for($this->anna)->for($this->emby)->create(['status' => AccountStatus::Active]);

        $job = (new RunConnector($this->lastRun()))->withFakeQueueInteractions();
        $job->handle(app(Connectors::class));

        $job->assertFailed();
        Http::assertSentCount(1);
        $this->assertStringContainsString('HTTP 302', (string) $this->lastRun()->message);
    }

    public function test_a_missing_configuration_fails_right_away(): void
    {
        Http::fake();
        Queue::fake();
        $this->emby->update(['connector_config' => ['base_url' => 'http://wizarr:5690']]);
        ServiceAccount::factory()->for($this->anna)->for($this->emby)->create(['status' => AccountStatus::Active]);

        $job = (new RunConnector($this->lastRun()))->withFakeQueueInteractions();
        $job->handle(app(Connectors::class));

        $job->assertFailed();
        Http::assertNothingSent();
    }
}

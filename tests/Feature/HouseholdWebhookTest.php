<?php

namespace Tests\Feature;

use App\Enums\HouseholdRole;
use App\Enums\WebhookStatus;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Households\Households;
use App\Jobs\DeliverWebhook;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Webhooks\WebhookSignature;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class HouseholdWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Service $recipes;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->recipes = Service::factory()->create(['key' => 'recipes', 'webhook_url' => 'https://recipes.example.com/api/konto/webhook']);
        $this->secret = $this->recipes->rotateSecret();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithAccount(string $sub, ?Service $service = null, array $attributes = []): User
    {
        $user = User::factory()->create(['oidc_sub' => $sub, ...$attributes]);
        ServiceAccount::factory()->for($user)->for($service ?? $this->recipes)->create();

        return $user;
    }

    /**
     * @return list<array<mixed>>
     */
    private function sentEvents(string $host = 'recipes.example.com'): array
    {
        return array_values(Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $host))
            ->map(fn (array $pair): array => (array) json_decode($pair[0]->body(), true))
            ->all());
    }

    public function test_events_are_signed_and_carry_only_the_snapshot(): void
    {
        Http::fake(['recipes.example.com/*' => Http::response(null, 204)]);
        $anna = $this->userWithAccount('sub-anna', attributes: ['name' => 'Anna Muster', 'email' => 'anna@example.com']);

        $this->actingAs($anna)->post(route('households.store'), ['name' => 'Familie Muster']);
        $household = Household::query()->sole();

        Http::assertSent(function (Request $request) use ($household): bool {
            $body = $request->body();
            $event = json_decode($body, true);

            $this->assertSame('https://recipes.example.com/api/konto/webhook', $request->url());
            $this->assertSame('household.created', $request->header(WebhookSignature::EVENT_HEADER)[0]);
            $this->assertSame($event['event_id'], $request->header(WebhookSignature::EVENT_ID_HEADER)[0]);
            $this->assertTrue(WebhookSignature::verify($this->secret, $request->header(WebhookSignature::TIMESTAMP_HEADER)[0], $body, $request->header(WebhookSignature::SIGNATURE_HEADER)[0]));
            $this->assertTrue(Str::isUuid($event['event_id']));
            $this->assertSame('household.created', $event['type']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $event['occurred_at']);
            $this->assertSame([
                'household' => [
                    'uuid' => $household->uuid,
                    'name' => 'Familie Muster',
                    'version' => 1,
                    'members' => [['sub' => 'sub-anna', 'role' => 'admin']],
                ],
            ], $event['data']);

            // Privacy: no profile data leaves the AccountManager.
            $this->assertStringNotContainsString('anna@example.com', $body);
            $this->assertStringNotContainsString('Anna Muster', $body);

            return true;
        });

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookStatus::Delivered, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(204, $delivery->response_status);
        $this->assertSame('household.created', $delivery->event);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_only_services_with_a_webhook_and_an_account_of_a_member_get_events(): void
    {
        Http::fake();
        $other = Service::factory()->create(['webhook_url' => 'https://other.example.com/hook']);
        $other->rotateSecret();
        $withoutSecret = Service::factory()->create(['webhook_url' => 'https://nosecret.example.com/hook']);
        $withoutUrl = Service::factory()->create();
        $withoutUrl->rotateSecret();
        $anna = $this->userWithAccount('sub-anna');
        ServiceAccount::factory()->for($anna)->for($withoutSecret)->create();
        ServiceAccount::factory()->for($anna)->for($withoutUrl)->create();

        app(Households::class)->create($anna, 'Familie Muster');

        Http::assertSentCount(1);
        $this->assertSame([$this->recipes->id], WebhookDelivery::query()->pluck('service_id')->all());
    }

    public function test_member_events_carry_the_member_and_a_new_version(): void
    {
        Http::fake();
        $households = app(Households::class);
        $anna = $this->userWithAccount('sub-anna');
        // Ben only uses the shopping list: it learns about the household through him.
        $shopping = Service::factory()->create(['webhook_url' => 'https://shopping.example.com/hook']);
        $shopping->rotateSecret();
        $ben = $this->userWithAccount('sub-ben', $shopping, ['email' => 'ben@example.com']);

        $household = $households->create($anna, 'Familie Muster');
        $invitation = HouseholdInvitation::factory()->for($household)->create(['email' => 'ben@example.com']);
        $households->accept($invitation, $ben);
        $households->changeRole($household, $ben, HouseholdRole::Admin);
        $households->rename($household, 'Muster');
        $households->removeMember($household, $ben);

        $this->assertSame(
            ['household.created', 'household.member_added', 'household.member_updated', 'household.updated', 'household.member_removed'],
            array_column($this->sentEvents('recipes.example.com'), 'type'),
        );
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn (array $event): int => $event['data']['household']['version'], $this->sentEvents('recipes.example.com')));

        $shoppingEvents = $this->sentEvents('shopping.example.com');
        $this->assertSame(['household.member_added', 'household.member_updated', 'household.updated', 'household.member_removed'], array_column($shoppingEvents, 'type'));
        $this->assertSame(['sub' => 'sub-ben', 'role' => 'member'], $shoppingEvents[0]['data']['member']);
        $this->assertSame(['sub' => 'sub-ben', 'role' => 'admin'], $shoppingEvents[1]['data']['member']);
        $this->assertSame([['sub' => 'sub-anna', 'role' => 'admin'], ['sub' => 'sub-ben', 'role' => 'admin']], $shoppingEvents[2]['data']['household']['members']);
        // The removed member's app gets the removal too, with the members after it.
        $this->assertSame(['sub' => 'sub-ben', 'role' => 'admin'], $shoppingEvents[3]['data']['member']);
        $this->assertSame([['sub' => 'sub-anna', 'role' => 'admin']], $shoppingEvents[3]['data']['household']['members']);
    }

    public function test_deleting_sends_the_last_version_without_members(): void
    {
        Http::fake();
        $anna = $this->userWithAccount('sub-anna');
        $household = Household::factory()->withAdmin($anna)->create(['version' => 4]);

        app(Households::class)->delete($household);

        [$event] = $this->sentEvents();
        $this->assertSame('household.deleted', $event['type']);
        $this->assertSame(['uuid' => $household->uuid, 'name' => $household->name, 'version' => 5, 'members' => [], 'deleted' => true], $event['data']['household']);
    }

    public function test_members_without_an_identity_are_not_sent(): void
    {
        Http::fake();
        $anna = $this->userWithAccount('sub-anna');
        $local = User::factory()->create(['email' => 'local@example.com', 'oidc_sub' => null]);
        $household = Household::factory()->withAdmin($anna)->create();
        $invitation = HouseholdInvitation::factory()->for($household)->create(['email' => 'local@example.com']);

        app(Households::class)->accept($invitation, $local);

        [$event] = $this->sentEvents();
        $this->assertSame('household.updated', $event['type']);
        $this->assertSame(2, $event['data']['household']['version']);
        $this->assertSame([['sub' => 'sub-anna', 'role' => 'admin']], $event['data']['household']['members']);
        $this->assertArrayNotHasKey('member', $event['data']);
    }

    public function test_deleting_a_user_tells_the_apps(): void
    {
        Http::fake();
        $anna = $this->userWithAccount('sub-anna');
        $ben = $this->userWithAccount('sub-ben');
        $shared = Household::factory()->withAdmin($anna)->withMember($ben)->create();
        $alone = Household::factory()->withAdmin($anna)->create();

        $anna->delete();

        $events = collect($this->sentEvents())->keyBy(fn (array $event): string => $event['data']['household']['uuid']);
        $this->assertSame('household.member_removed', $events[$shared->uuid]['type']);
        $this->assertSame(['sub' => 'sub-anna', 'role' => 'admin'], $events[$shared->uuid]['data']['member']);
        $this->assertSame([['sub' => 'sub-ben', 'role' => 'admin']], $events[$shared->uuid]['data']['household']['members']);
        $this->assertSame('household.deleted', $events[$alone->uuid]['type']);
        $this->assertSame([], $events[$alone->uuid]['data']['household']['members']);
    }

    public function test_failed_deliveries_are_logged_and_retried_with_growing_delays(): void
    {
        Queue::fake();
        $anna = $this->userWithAccount('sub-anna');
        app(Households::class)->create($anna, 'Familie Muster');
        Queue::assertPushed(DeliverWebhook::class, 1);
        $delivery = WebhookDelivery::query()->sole();
        $job = new DeliverWebhook($delivery);

        Http::fakeSequence('recipes.example.com/*')
            ->push('Internal error', 500)
            ->pushFailedConnection('Connection timed out')
            ->push('ok', 200);

        try {
            $job->handle();
            $this->fail('A failed delivery must throw, so that the queue retries it.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WebhookStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->response_status);
        $this->assertSame('HTTP 500 Internal error', $delivery->last_error);

        try {
            $job->handle();
            $this->fail('A connection error must throw.');
        } catch (ConnectionException) {
        }

        $delivery->refresh();
        $this->assertSame(2, $delivery->attempts);
        $this->assertNull($delivery->response_status);
        $this->assertStringContainsString('Connection timed out', (string) $delivery->last_error);

        $job->handle();

        $delivery->refresh();
        $this->assertSame(WebhookStatus::Delivered, $delivery->status);
        $this->assertSame(3, $delivery->attempts);
        $this->assertNull($delivery->last_error);

        // The same bytes and event id on every attempt.
        $bodies = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->unique();
        $this->assertCount(1, $bodies);

        $this->assertSame(8, $job->tries);
        $backoff = $job->backoff();
        $this->assertCount($job->tries - 1, $backoff);
        $this->assertSame($backoff, collect($backoff)->sort()->values()->all());
    }

    public function test_deliveries_fail_after_the_last_attempt_and_can_be_sent_again(): void
    {
        Queue::fake();
        $anna = $this->userWithAccount('sub-anna');
        app(Households::class)->create($anna, 'Familie Muster');
        $delivery = WebhookDelivery::query()->sole();

        (new DeliverWebhook($delivery))->failed(new RuntimeException('HTTP 500'));
        $this->assertSame(WebhookStatus::Failed, $delivery->fresh()->status);

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListWebhookDeliveries::class)
            ->assertCanSeeTableRecords([$delivery])
            ->callAction(TestAction::make('retry')->table($delivery));

        $this->assertSame(WebhookStatus::Pending, $delivery->fresh()->status);
        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    public function test_events_inside_a_failed_change_are_not_sent(): void
    {
        Http::fake();
        $anna = $this->userWithAccount('sub-anna');

        try {
            DB::transaction(function () use ($anna): void {
                app(Households::class)->create($anna, 'Familie Muster');

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_the_signature_rejects_other_secrets_bodies_and_old_timestamps(): void
    {
        $now = 1_800_000_000;
        $signature = WebhookSignature::sign('secret', $now, '{"a":1}');

        $this->assertTrue(WebhookSignature::verify('secret', (string) $now, '{"a":1}', $signature, $now + 299));
        $this->assertFalse(WebhookSignature::verify('other', (string) $now, '{"a":1}', $signature, $now));
        $this->assertFalse(WebhookSignature::verify('secret', (string) $now, '{"a":2}', $signature, $now));
        $this->assertFalse(WebhookSignature::verify('secret', (string) $now, '{"a":1}', $signature, $now + 301));
        $this->assertFalse(WebhookSignature::verify('secret', 'abc', '{"a":1}', $signature, $now));
        $this->assertSame('sha256='.hash_hmac('sha256', $now.'.{"a":1}', 'secret'), $signature);
    }

    public function test_admins_generate_the_secret_once_and_it_is_stored_encrypted(): void
    {
        $service = Service::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('rotateSecret')
            ->assertActionMounted('showSecret');

        $secret = (string) $service->fresh()->secret;
        $this->assertSame(64, strlen($secret));
        $page->assertMountedActionModalSee($secret);
        $this->assertNotNull($service->fresh()->secret_created_at);
        $this->assertNotSame($secret, DB::table('services')->where('id', $service->id)->value('secret'));
        $this->assertArrayNotHasKey('secret', $service->fresh()->toArray());

        // Rotating replaces it; saving the form keeps it.
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])->callAction('rotateSecret');
        $this->assertNotSame($secret, $rotated = (string) $service->fresh()->secret);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['webhook_url' => 'https://app.example.com/hook'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($rotated, $service->fresh()->secret);
        $this->assertSame('https://app.example.com/hook', $service->fresh()->webhook_url);
    }
}

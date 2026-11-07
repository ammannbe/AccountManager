<?php

namespace Tests\Feature;

use App\Accounts\Access;
use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Feature;
use App\Models\FeatureGrant;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Webhooks\WebhookSignature;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EntitlementWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Service $shopping;

    private string $secret;

    private Feature $ai;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        $this->shopping = Service::factory()->create(['key' => 'shoppinglist', 'webhook_url' => 'https://shopping.example.com/api/account-manager/webhook']);
        $this->secret = $this->shopping->rotateSecret();
        $this->ai = Feature::factory()->for($this->shopping)->create(['key' => 'shoppinglist.ai']);
    }

    /**
     * Events of one type posted to a host, decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function events(string $type = 'entitlements.changed', string $host = 'shopping.example.com'): array
    {
        return array_values(Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $host))
            ->map(fn (array $pair): array => (array) json_decode($pair[0]->body(), true))
            ->filter(fn (array $event): bool => $event['type'] === $type)
            ->all());
    }

    /**
     * The entitlements of the last event to the host.
     *
     * @return array<string, mixed>|null
     */
    private function last(string $host = 'shopping.example.com'): ?array
    {
        $events = $this->events(host: $host);

        return $events === [] ? null : $events[array_key_last($events)]['data']['entitlements'];
    }

    private function user(string $sub = 'sub-anna', AccountStatus $status = AccountStatus::Active): User
    {
        $user = User::factory()->create(['oidc_sub' => $sub, 'name' => 'Anna Muster', 'email' => 'anna@example.com']);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create(['status' => $status]);

        return $user;
    }

    public function test_events_are_signed_and_carry_only_the_snapshot(): void
    {
        $user = $this->user();
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id, 'note' => 'Testerin']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();
            $event = json_decode($body, true);

            $this->assertSame('https://shopping.example.com/api/account-manager/webhook', $request->url());
            $this->assertSame('entitlements.changed', $request->header(WebhookSignature::EVENT_HEADER)[0]);
            $this->assertSame($event['event_id'], $request->header(WebhookSignature::EVENT_ID_HEADER)[0]);
            $this->assertTrue(WebhookSignature::verify($this->secret, $request->header(WebhookSignature::TIMESTAMP_HEADER)[0], $body, $request->header(WebhookSignature::SIGNATURE_HEADER)[0]));
            $this->assertTrue(Str::isUuid($event['event_id']));
            $this->assertSame(['event_id', 'type', 'occurred_at', 'data'], array_keys($event));
            $this->assertSame(['entitlements'], array_keys($event['data']));
            $this->assertSame(['sub', 'service', 'status', 'admin', 'features', 'version'], array_keys($event['data']['entitlements']));

            // Privacy: no profile data, notes or plan details leave the AccountManager.
            $this->assertStringNotContainsString('anna@example.com', $body);
            $this->assertStringNotContainsString('Anna Muster', $body);
            $this->assertStringNotContainsString('Testerin', $body);

            return true;
        });

        $this->assertSame(['sub-anna', 'sub-anna'], array_column(array_column(array_column($this->events(), 'data'), 'entitlements'), 'sub'));
        $this->assertSame(
            ['sub' => 'sub-anna', 'service' => 'shoppinglist', 'status' => 'active', 'admin' => false, 'features' => ['shoppinglist.ai'], 'version' => 2],
            $this->last(),
        );
    }

    public function test_every_change_gets_the_next_version_and_unchanged_snapshots_send_nothing(): void
    {
        $user = $this->user(status: AccountStatus::Requested);
        $account = $user->serviceAccounts()->sole();
        $plan = Plan::factory()->create(['service_id' => $this->shopping->id]);
        $plan->features()->attach($this->ai);

        $this->assertSame(['status' => 'requested', 'version' => 1], array_intersect_key($this->last() ?? [], ['status' => 1, 'version' => 1]));

        // Plans of a requested account change nothing the app sees.
        $subscription = Subscription::factory()->for($user)->for($plan)->create();
        $this->assertCount(1, $this->events());

        app(Access::class)->approve($account);
        $this->assertSame(['active', ['shoppinglist.ai'], 2], [$this->last()['status'], $this->last()['features'], $this->last()['version']]);

        $account->update(['note' => 'Only a note', 'external_username' => 'anna']);
        $subscription->update(['note' => 'Only a note']);
        $this->assertCount(2, $this->events());

        $account->update(['is_admin' => true]);
        $this->assertSame([true, 3], [$this->last()['admin'], $this->last()['version']]);

        app(Access::class)->suspend($account->fresh(), 'Offene Rechnung');
        $this->assertSame(['suspended', [], false, 4], [$this->last()['status'], $this->last()['features'], $this->last()['admin'], $this->last()['version']]);

        app(Access::class)->reactivate($account->fresh());
        $this->assertSame(['active', ['shoppinglist.ai'], true, 5], [$this->last()['status'], $this->last()['features'], $this->last()['admin'], $this->last()['version']]);

        $subscription->fresh()?->update(['status' => SubscriptionStatus::Suspended]);
        $this->assertSame([[], 6], [$this->last()['features'], $this->last()['version']]);

        $subscription->fresh()?->update(['status' => SubscriptionStatus::Active]);
        $subscription->fresh()?->end();
        $this->assertSame([[], 8], [$this->last()['features'], $this->last()['version']]);

        $account->fresh()?->delete();
        $this->assertSame(['none', [], false, 9], [$this->last()['status'], $this->last()['features'], $this->last()['admin'], $this->last()['version']]);

        // A new account continues the versions, so the app never sees an older number again.
        ServiceAccount::factory()->for($user)->for($this->shopping)->create();
        $this->assertSame(['active', 10], [$this->last()['status'], $this->last()['version']]);
    }

    public function test_grants_plans_and_features_trigger_events(): void
    {
        $user = $this->user();
        $plan = Plan::factory()->create(['service_id' => $this->shopping->id]);
        Subscription::factory()->for($user)->for($plan)->create();
        $this->assertSame([], $this->last()['features']);

        // Adding a feature to the plan in the panel.
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel('admin');
        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['features' => [$this->ai->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['shoppinglist.ai'], $this->last()['features']);

        $this->ai->update(['key' => 'shoppinglist.ai-planning']);
        $this->assertSame(['shoppinglist.ai-planning'], $this->last()['features']);

        $grant = FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => Feature::factory()->for($this->shopping)->create(['key' => 'shoppinglist.beta'])->id]);
        $this->assertSame(['shoppinglist.ai-planning', 'shoppinglist.beta'], $this->last()['features']);

        $grant->delete();
        $this->assertSame(['shoppinglist.ai-planning'], $this->last()['features']);

        $this->ai->delete();
        $this->assertSame([], $this->last()['features']);
        $this->assertSame(6, $this->last()['version']);
    }

    public function test_only_the_concerned_service_gets_the_event(): void
    {
        $other = Service::factory()->create(['webhook_url' => 'https://other.example.com/hook']);
        $other->rotateSecret();
        $withoutSecret = Service::factory()->create(['webhook_url' => 'https://nosecret.example.com/hook']);
        $user = $this->user();
        ServiceAccount::factory()->for($user)->for($other)->create();
        ServiceAccount::factory()->for($user)->for($withoutSecret)->create();

        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

        // New account (version 1) and the grant (version 2); the other app only got its new account.
        $this->assertCount(2, $this->events());
        $this->assertSame(['service' => $other->key, 'version' => 1], array_intersect_key($this->last('other.example.com') ?? [], ['service' => 1, 'version' => 1]));
        $this->assertCount(1, $this->events(host: 'other.example.com'));
        $this->assertSame([], $this->events(host: 'nosecret.example.com'));
        $this->assertSame(0, WebhookDelivery::query()->where('service_id', $withoutSecret->id)->count());
    }

    public function test_users_without_identity_are_not_sent_until_they_are_linked(): void
    {
        $user = User::factory()->create(['oidc_sub' => null]);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create();
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);
        $this->assertSame([], $this->events());

        $user->forceFill(['oidc_sub' => 'sub-linked'])->save();

        $this->assertSame(['sub' => 'sub-linked', 'service' => 'shoppinglist', 'status' => 'active', 'admin' => false, 'features' => ['shoppinglist.ai'], 'version' => 1], $this->last());
    }

    public function test_the_scheduled_refresh_sends_subscriptions_that_started_or_ended(): void
    {
        $user = $this->user();
        $plan = Plan::factory()->create(['service_id' => $this->shopping->id]);
        $plan->features()->attach($this->ai);
        Subscription::factory()->for($user)->for($plan)->create(['starts_at' => now()->addMinutes(10), 'ends_at' => now()->addMinutes(40)]);
        $this->assertSame([], $this->last()['features']);

        $this->travel(15)->minutes();
        $this->assertSame(0, Artisan::call('entitlements:refresh'));
        $this->assertSame(['shoppinglist.ai'], $this->last()['features']);

        $this->assertSame(0, Artisan::call('entitlements:refresh'));
        $this->assertCount(2, $this->events());

        $this->travel(30)->minutes();
        $this->assertSame(0, Artisan::call('entitlements:refresh'));
        $this->assertSame([[], 3], [$this->last()['features'], $this->last()['version']]);
        $this->assertSame(SubscriptionStatus::Ended, Subscription::query()->sole()->status);
    }

    public function test_deleting_a_user_sends_user_deleted_to_every_app_they_had_an_account_in(): void
    {
        $recipes = Service::factory()->create(['webhook_url' => 'https://recipes.example.com/hook']);
        $recipes->rotateSecret();
        $former = Service::factory()->create(['webhook_url' => 'https://former.example.com/hook']);
        $former->rotateSecret();
        $unrelated = Service::factory()->create(['webhook_url' => 'https://unrelated.example.com/hook']);
        $unrelated->rotateSecret();

        $user = $this->user();
        ServiceAccount::factory()->for($user)->for($recipes)->create(['status' => AccountStatus::Suspended]);
        ServiceAccount::factory()->for($user)->for($former)->create()->delete();

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel('admin');
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->callAction('delete');

        $this->assertNull($user->fresh());

        foreach (['shopping.example.com', 'recipes.example.com', 'former.example.com'] as $host) {
            $events = $this->events('user.deleted', $host);
            $this->assertCount(1, $events, $host);
            $this->assertSame(['user' => ['sub' => 'sub-anna']], $events[0]['data']);
        }

        $this->assertSame([], $this->events('user.deleted', 'unrelated.example.com'));
        // One event for all apps.
        $this->assertSame(1, WebhookDelivery::query()->where('event', 'user.deleted')->distinct()->count('event_id'));
        $this->assertSame(3, WebhookDelivery::query()->where('event', 'user.deleted')->count());

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'anna@example.com') && ! str_contains($request->body(), 'Anna Muster'));
    }

    public function test_users_without_identity_send_no_user_deleted(): void
    {
        $user = User::factory()->create(['oidc_sub' => null]);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create();

        $user->delete();

        $this->assertSame([], $this->events('user.deleted'));
    }
}

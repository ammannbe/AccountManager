<?php

namespace Tests\Feature;

use App\Entitlements\Entitlements;
use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Feature;
use App\Models\FeatureGrant;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private Service $shopping;

    private Service $recipes;

    private Feature $ai;

    private Feature $export;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        $this->shopping = Service::factory()->create(['key' => 'shoppinglist', 'access_group' => 'svc-shoppinglist', 'admin_group' => 'svc-shoppinglist-admin']);
        $this->recipes = Service::factory()->create(['key' => 'recipes', 'access_group' => 'svc-recipes']);
        $this->ai = Feature::factory()->for($this->shopping)->create(['key' => 'shoppinglist.ai', 'name' => 'KI-Wochenplanung', 'group' => 'feat-shoppinglist-ai']);
        $this->export = Feature::factory()->for($this->shopping)->create(['key' => 'shoppinglist.export', 'name' => 'Export']);
    }

    private function user(AccountStatus $status = AccountStatus::Active, bool $admin = false): User
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-'.fake()->unique()->numberBetween(1, 9999)]);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create(['status' => $status, 'is_admin' => $admin]);

        return $user;
    }

    /**
     * @param  list<Feature>  $features
     */
    private function plan(array $features, ?Service $service = null): Plan
    {
        $plan = Plan::factory()->create(['service_id' => $service?->id]);
        $plan->features()->attach(array_map(fn (Feature $feature): int => $feature->id, $features));

        return $plan;
    }

    /**
     * @return array{sub: string|null, service: string, status: string, admin: bool, features: list<string>}
     */
    private function compute(User $user, ?Service $service = null): array
    {
        return app(Entitlements::class)->compute($user, $service ?? $this->shopping);
    }

    public function test_features_come_from_active_plans_and_direct_grants(): void
    {
        $user = $this->user();
        $this->assertSame([], $this->compute($user)['features']);

        Subscription::factory()->for($user)->for($this->plan([$this->export], $this->shopping))->create();
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

        $this->assertSame([
            'sub' => $user->oidc_sub,
            'service' => 'shoppinglist',
            'status' => 'active',
            'admin' => false,
            // Sorted by key, each once.
            'features' => ['shoppinglist.ai', 'shoppinglist.export'],
        ], $this->compute($user));
    }

    public function test_a_bundle_unlocks_features_in_every_service_with_an_active_account(): void
    {
        $user = $this->user();
        ServiceAccount::factory()->for($user)->for($this->recipes)->create();
        $cookbooks = Feature::factory()->for($this->recipes)->create(['key' => 'recipes.cookbooks']);
        Subscription::factory()->for($user)->for($this->plan([$this->ai, $cookbooks]))->create();

        $this->assertSame(['shoppinglist.ai'], $this->compute($user)['features']);
        $this->assertSame(['recipes.cookbooks'], $this->compute($user, $this->recipes)['features']);
    }

    public function test_only_active_accounts_get_features_and_the_admin_flag(): void
    {
        foreach ([AccountStatus::Requested, AccountStatus::Suspended] as $status) {
            $user = $this->user($status, admin: true);
            FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

            $snapshot = $this->compute($user);
            $this->assertSame($status->value, $snapshot['status']);
            $this->assertSame([], $snapshot['features']);
            $this->assertFalse($snapshot['admin']);
        }

        $admin = $this->user(admin: true);
        $this->assertTrue($this->compute($admin)['admin']);

        // Without an account a grant unlocks nothing.
        $this->assertSame(['sub' => $admin->oidc_sub, 'service' => 'recipes', 'status' => 'none', 'admin' => false, 'features' => []], $this->compute($admin, $this->recipes));
    }

    public function test_only_effective_subscriptions_count(): void
    {
        $user = $this->user();
        $plan = $this->plan([$this->ai], $this->shopping);

        Subscription::factory()->for($user)->for($plan)->create(['starts_at' => now()->addDay()]);
        Subscription::factory()->for($user)->for($plan)->create(['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);
        Subscription::factory()->for($user)->for($plan)->create(['status' => SubscriptionStatus::Suspended]);
        Subscription::factory()->for($user)->for($plan)->create(['status' => SubscriptionStatus::Ended]);
        $this->assertSame([], $this->compute($user)['features']);

        $running = Subscription::factory()->for($user)->for($plan)->create(['ends_at' => now()->addDay()]);
        $this->assertSame(['shoppinglist.ai'], $this->compute($user)['features']);
        $this->assertTrue($running->isEffective());

        $running->end();
        $this->assertSame(SubscriptionStatus::Ended, $running->fresh()->status);
        $this->assertSame([], $this->compute($user)['features']);
    }

    public function test_groups_follow_access_admin_flag_and_features(): void
    {
        $idp = $this->fakeIdentityProvider();
        $user = User::factory()->create(['oidc_sub' => 'idp-1']);
        $idp->groups['idp-1'] = ['family'];
        $account = ServiceAccount::factory()->for($user)->for($this->shopping)->create(['is_admin' => true]);

        $this->assertSame(['family', 'svc-shoppinglist', 'svc-shoppinglist-admin'], $idp->groups['idp-1']);

        $grant = FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);
        $this->assertSame(['family', 'feat-shoppinglist-ai', 'svc-shoppinglist', 'svc-shoppinglist-admin'], $idp->groups['idp-1']);

        $account->update(['is_admin' => false]);
        $this->assertSame(['family', 'feat-shoppinglist-ai', 'svc-shoppinglist'], $idp->groups['idp-1']);

        $grant->delete();
        $this->assertSame(['family', 'svc-shoppinglist'], $idp->groups['idp-1']);

        $this->assertSame(['feat-shoppinglist-ai', 'svc-recipes', 'svc-shoppinglist', 'svc-shoppinglist-admin'], Service::managedGroups());
    }

    public function test_the_scheduled_refresh_ends_expired_and_announces_started_subscriptions(): void
    {
        $user = $this->user();
        $plan = $this->plan([$this->ai], $this->shopping);
        $this->travelTo(now()->subHours(2));
        $expiring = Subscription::factory()->for($user)->for($plan)->create(['ends_at' => now()->addHour()]);
        $starting = Subscription::factory()->for(User::factory()->create(['oidc_sub' => 'sub-later']))->for($plan)->create(['starts_at' => now()->addHour()]);
        ServiceAccount::factory()->for($starting->user)->for($this->shopping)->create();
        $this->travelBack();

        $this->assertSame(0, Artisan::call('entitlements:refresh'));

        $this->assertSame(SubscriptionStatus::Ended, $expiring->fresh()->status);
        $this->assertSame([], app(Entitlements::class)->snapshot($user, $this->shopping)['features'] ?? null);
        $this->assertSame(['shoppinglist.ai'], app(Entitlements::class)->snapshot($starting->user, $this->shopping)['features'] ?? null);
    }

    public function test_the_portal_shows_plans_and_features_of_active_accounts(): void
    {
        $user = $this->user();
        Subscription::factory()->for($user)->for($this->plan([$this->export], $this->shopping))->create(['ends_at' => now()->addMonth()]);
        $plan = Plan::query()->sole();
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

        $this->actingAs($user)->get(route('portal'))
            ->assertOk()
            ->assertSee($plan->name)
            ->assertSee('KI-Wochenplanung')
            ->assertSee('Export');

        $user->serviceAccounts()->sole()->update(['status' => AccountStatus::Suspended]);

        $this->actingAs($user)->get(route('portal'))->assertOk()->assertDontSee('KI-Wochenplanung')->assertDontSee($plan->name);
    }
}

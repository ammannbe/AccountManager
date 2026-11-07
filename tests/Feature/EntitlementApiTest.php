<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Feature;
use App\Models\FeatureGrant;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EntitlementApiTest extends TestCase
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
        $this->shopping = Service::factory()->create(['key' => 'shoppinglist']);
        $this->secret = $this->shopping->rotateSecret();
        $this->ai = Feature::factory()->for($this->shopping)->create(['key' => 'shoppinglist.ai']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function api(string $uri, ?string $key = 'shoppinglist', ?string $secret = null): TestResponse
    {
        $headers = $key === null ? [] : ['Authorization' => 'Basic '.base64_encode($key.':'.($secret ?? $this->secret))];

        return $this->getJson($uri, $headers);
    }

    public function test_requests_without_valid_credentials_are_rejected(): void
    {
        $other = Service::factory()->create(['key' => 'recipes']);
        $otherSecret = $other->rotateSecret();

        $this->api('/api/entitlements/sub-anna', key: null)->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->api('/api/entitlements/sub-anna', secret: 'wrong')->assertUnauthorized();
        $this->api('/api/entitlements/sub-anna', secret: $otherSecret)->assertUnauthorized();
        $this->getJson('/api/entitlements/sub-anna', ['Authorization' => 'Bearer '.$this->secret])->assertUnauthorized();
    }

    public function test_the_snapshot_of_a_user_for_the_calling_service(): void
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-anna', 'email' => 'anna@example.com']);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create(['is_admin' => true]);
        $plan = Plan::factory()->create(['service_id' => $this->shopping->id]);
        $plan->features()->attach($this->ai);
        Subscription::factory()->for($user)->for($plan)->create();

        $response = $this->api('/api/entitlements/sub-anna')->assertOk();

        $this->assertSame(['data' => [
            'sub' => 'sub-anna',
            'service' => 'shoppinglist',
            'status' => 'active',
            'admin' => true,
            'features' => ['shoppinglist.ai'],
            'version' => 2,
        ]], $response->json());
        $this->assertStringNotContainsString('anna@example.com', (string) $response->getContent());

        // Reading does not change the version.
        $this->assertSame(2, $this->api('/api/entitlements/sub-anna')->json('data.version'));
    }

    public function test_unknown_users_and_users_without_an_account_here_are_not_found(): void
    {
        $recipes = Service::factory()->create(['key' => 'recipes']);
        $recipesSecret = $recipes->rotateSecret();
        $user = User::factory()->create(['oidc_sub' => 'sub-anna']);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create();
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

        $this->api('/api/entitlements/unknown')->assertNotFound();
        $this->api('/api/entitlements/sub-anna', key: 'recipes', secret: $recipesSecret)->assertNotFound();

        // After the account is deleted the app gets 404 (the last event said "none").
        $user->serviceAccounts()->sole()->delete();
        $this->api('/api/entitlements/sub-anna')->assertNotFound();
    }

    public function test_suspended_and_requested_accounts_get_their_status_without_features(): void
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-anna']);
        $account = ServiceAccount::factory()->for($user)->for($this->shopping)->create(['status' => AccountStatus::Suspended, 'is_admin' => true]);
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

        $this->api('/api/entitlements/sub-anna')->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.features', [])->assertJsonPath('data.admin', false);

        $account->update(['status' => AccountStatus::Requested]);
        $this->api('/api/entitlements/sub-anna')->assertOk()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.features', []);
    }

    public function test_the_api_recomputes_before_answering(): void
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-anna']);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create();
        $plan = Plan::factory()->create(['service_id' => $this->shopping->id]);
        $plan->features()->attach($this->ai);
        Subscription::factory()->for($user)->for($plan)->create(['ends_at' => now()->addMinutes(5)]);
        $this->assertSame(['shoppinglist.ai'], $this->api('/api/entitlements/sub-anna')->json('data.features'));

        // Ended before the scheduled refresh ran: the answer is already current, with a new version.
        $this->travel(10)->minutes();

        $this->api('/api/entitlements/sub-anna')->assertOk()->assertJsonPath('data.features', [])->assertJsonPath('data.version', 3);
    }
}

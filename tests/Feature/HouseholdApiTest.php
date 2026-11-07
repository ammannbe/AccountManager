<?php

namespace Tests\Feature;

use App\Enums\HouseholdImportStatus;
use App\Enums\HouseholdRole;
use App\Filament\Resources\HouseholdImports\Pages\ListHouseholdImports;
use App\Models\Household;
use App\Models\HouseholdImport;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class HouseholdApiTest extends TestCase
{
    use RefreshDatabase;

    private Service $recipes;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->recipes = Service::factory()->create(['key' => 'recipes']);
        $this->secret = $this->recipes->rotateSecret();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function api(string $method, string $uri, array $data = [], ?string $key = 'recipes', ?string $secret = null): TestResponse
    {
        $headers = $key === null ? [] : ['Authorization' => 'Basic '.base64_encode($key.':'.($secret ?? $this->secret))];

        return $this->json($method, $uri, $data, $headers);
    }

    private function user(string $sub, ?Service $service = null): User
    {
        $user = User::factory()->create(['oidc_sub' => $sub]);

        if ($service !== null) {
            ServiceAccount::factory()->for($user)->for($service)->create();
        }

        return $user;
    }

    public function test_requests_without_valid_credentials_are_rejected(): void
    {
        $other = Service::factory()->create(['key' => 'shopping']);
        $otherSecret = $other->rotateSecret();
        $withoutSecret = Service::factory()->create(['key' => 'tasks']);

        $this->api('GET', '/api/households?sub=x', key: null)->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->api('GET', '/api/households?sub=x', secret: 'wrong')->assertUnauthorized();
        $this->api('GET', '/api/households?sub=x', key: 'unknown')->assertUnauthorized();
        // The secret of another service does not work for this one.
        $this->api('GET', '/api/households?sub=x', secret: $otherSecret)->assertUnauthorized();
        $this->api('GET', '/api/households?sub=x', key: $withoutSecret->key, secret: '')->assertUnauthorized();
        $this->json('GET', '/api/households?sub=x', [], ['Authorization' => 'Bearer '.$this->secret])->assertUnauthorized();

        $this->api('GET', '/api/households?sub=x')->assertOk();
        $this->api('GET', '/api/households?sub=x', key: 'shopping', secret: $otherSecret)->assertOk();
    }

    public function test_the_households_of_a_user_with_full_snapshots(): void
    {
        $anna = $this->user('sub-anna', $this->recipes);
        $ben = $this->user('sub-ben');
        $household = Household::factory()->withAdmin($anna)->withMember($ben)->create(['name' => 'Familie Muster', 'version' => 3]);
        Household::factory()->withAdmin($ben)->create();

        $this->api('GET', '/api/households?sub=sub-anna')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'uuid' => $household->uuid,
                'name' => 'Familie Muster',
                'version' => 3,
                'members' => [['sub' => 'sub-anna', 'role' => 'admin'], ['sub' => 'sub-ben', 'role' => 'member']],
            ]]]);

        $this->api('GET', '/api/households')->assertUnprocessable()->assertJsonValidationErrors('sub');
        $this->api('GET', '/api/households?sub=unknown')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_a_service_only_sees_households_that_concern_its_users(): void
    {
        $shopping = Service::factory()->create(['key' => 'shopping']);
        $shoppingSecret = $shopping->rotateSecret();
        // Ben uses only the shopping list.
        $ben = $this->user('sub-ben', $shopping);
        $household = Household::factory()->withAdmin($ben)->create();

        $this->api('GET', '/api/households?sub=sub-ben')->assertOk()->assertExactJson(['data' => []]);
        $this->api('GET', "/api/households/{$household->uuid}")->assertNotFound();
        $this->api('PUT', "/api/households/{$household->uuid}/shares", ['shares' => []])->assertNotFound();

        $this->api('GET', "/api/households/{$household->uuid}", key: 'shopping', secret: $shoppingSecret)
            ->assertOk()
            ->assertJsonPath('data.uuid', $household->uuid)
            ->assertJsonPath('data.members', [['sub' => 'sub-ben', 'role' => 'admin']]);
    }

    public function test_a_deleted_household_is_not_found(): void
    {
        $anna = $this->user('sub-anna', $this->recipes);
        $household = Household::factory()->withAdmin($anna)->create();
        $uuid = $household->uuid;
        $household->delete();

        $this->api('GET', "/api/households/{$uuid}")->assertNotFound();
        $this->api('GET', '/api/households/not-a-uuid')->assertNotFound();
    }

    public function test_apps_replace_their_shares_and_do_not_touch_those_of_other_apps(): void
    {
        $shopping = Service::factory()->create(['key' => 'shopping']);
        $anna = $this->user('sub-anna', $this->recipes);
        $household = Household::factory()->withAdmin($anna)->create();
        $household->shares()->create(['service_id' => $shopping->id, 'resource_type' => 'list', 'resource_id' => '1', 'label' => 'Wocheneinkauf']);
        $household->shares()->create(['service_id' => $this->recipes->id, 'resource_type' => 'cookbook', 'resource_id' => '9', 'label' => 'Alt']);

        $this->api('PUT', "/api/households/{$household->uuid}/shares", ['shares' => [
            ['resource_type' => 'cookbook', 'resource_id' => '12', 'label' => 'Familienrezepte'],
            ['resource_type' => 'cookbook', 'resource_id' => '13', 'label' => 'Backen'],
        ]])
            ->assertOk()
            ->assertExactJson(['data' => ['shares' => [
                ['resource_type' => 'cookbook', 'resource_id' => '12', 'label' => 'Familienrezepte'],
                ['resource_type' => 'cookbook', 'resource_id' => '13', 'label' => 'Backen'],
            ]]]);

        $this->assertSame(['Backen', 'Familienrezepte', 'Wocheneinkauf'], $household->shares()->orderBy('label')->pluck('label')->all());

        $this->api('PUT', "/api/households/{$household->uuid}/shares", ['shares' => []])->assertOk();
        $this->assertSame(['Wocheneinkauf'], $household->shares()->pluck('label')->all());

        $this->api('PUT', "/api/households/{$household->uuid}/shares", ['shares' => [['resource_type' => 'cookbook']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shares.0.resource_id', 'shares.0.label']);

        $this->actingAs($anna)->get(route('households.index'))->assertSee('Wocheneinkauf');
    }

    public function test_apps_propose_their_local_households_and_poll_the_result(): void
    {
        $anna = $this->user('sub-anna', $this->recipes);
        $this->user('sub-ben', $this->recipes);

        $proposal = ['households' => [
            ['local_id' => '7', 'name' => 'Familie Muster', 'members' => [['sub' => 'sub-anna', 'role' => 'admin'], ['sub' => 'sub-ben', 'role' => 'member'], ['sub' => 'sub-unknown', 'role' => 'member']]],
            ['local_id' => '8', 'name' => 'WG', 'members' => [['sub' => 'sub-ben']]],
        ]];

        $this->api('POST', '/api/households/import', $proposal)
            ->assertStatus(202)
            ->assertExactJson(['data' => [
                ['local_id' => '7', 'status' => 'pending', 'household_uuid' => null, 'household' => null],
                ['local_id' => '8', 'status' => 'pending', 'household_uuid' => null, 'household' => null],
            ]]);

        // Sending again updates the pending proposals instead of duplicating them.
        $this->api('POST', '/api/households/import', $proposal)->assertStatus(202);
        $this->assertSame(2, HouseholdImport::query()->count());
        $import = HouseholdImport::query()->where('local_id', '7')->sole();
        $this->assertSame(['sub-unknown'], $import->unknownSubs());

        $this->api('POST', '/api/households/import', ['households' => [['local_id' => '9', 'name' => 'X', 'members' => []]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('households.0.members');
        $this->api('GET', '/api/households/import', key: 'unknown')->assertUnauthorized();

        // An admin creates the household; the app polls the mapping.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListHouseholdImports::class)
            ->assertCanSeeTableRecords([$import])
            ->callAction(TestAction::make('create')->table($import));

        $household = Household::query()->sole();
        $this->assertSame('Familie Muster', $household->name);
        $this->assertSame(HouseholdRole::Admin, $household->roleOf($anna));
        $this->assertSame(2, $household->members()->count());

        $this->api('GET', '/api/households/import')
            ->assertOk()
            ->assertJsonPath('data.0.local_id', '7')
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.0.household_uuid', $household->uuid)
            ->assertJsonPath('data.0.household.members', [['sub' => 'sub-anna', 'role' => 'admin'], ['sub' => 'sub-ben', 'role' => 'member']])
            ->assertJsonPath('data.1.status', 'pending');

        // Resolved proposals stay as they are when the app sends them again.
        $this->api('POST', '/api/households/import', ['households' => [['local_id' => '7', 'name' => 'Anders', 'members' => [['sub' => 'sub-anna']]]]])
            ->assertJsonPath('data.0.status', 'confirmed');
        $this->assertSame('Familie Muster', $import->fresh()->name);
    }

    public function test_admins_merge_proposals_into_an_existing_household_or_reject_them(): void
    {
        $anna = $this->user('sub-anna', $this->recipes);
        $ben = $this->user('sub-ben', $this->recipes);
        $carla = $this->user('sub-carla', $this->recipes);
        $existing = Household::factory()->withAdmin($anna)->create(['name' => 'Familie Muster']);
        $merge = HouseholdImport::query()->create(['service_id' => $this->recipes->id, 'local_id' => '1', 'name' => 'familie muster', 'members' => [['sub' => 'sub-anna', 'role' => 'member'], ['sub' => 'sub-ben', 'role' => 'member']]]);
        $reject = HouseholdImport::query()->create(['service_id' => $this->recipes->id, 'local_id' => '2', 'name' => 'Spam', 'members' => [['sub' => 'sub-carla', 'role' => 'admin']]]);
        $unknown = HouseholdImport::query()->create(['service_id' => $this->recipes->id, 'local_id' => '3', 'name' => 'Nobody', 'members' => [['sub' => 'sub-nobody', 'role' => 'admin']]]);

        $this->assertTrue($merge->suggestedHousehold()?->is($existing));

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListHouseholdImports::class)
            ->assertActionHidden(TestAction::make('create')->table($unknown))
            ->mountAction(TestAction::make('merge')->table($merge))
            ->assertSchemaStateSet(['household_id' => $existing->id])
            ->callMountedAction()
            ->callAction(TestAction::make('reject')->table($reject));

        // Existing members keep their role, missing ones are added.
        $this->assertSame(HouseholdRole::Admin, $existing->roleOf($anna));
        $this->assertSame(HouseholdRole::Member, $existing->roleOf($ben));
        $this->assertSame(2, $existing->fresh()->version);
        $this->assertSame(HouseholdImportStatus::Confirmed, $merge->fresh()->status);
        $this->assertTrue($merge->fresh()->household->is($existing));
        $this->assertSame(HouseholdImportStatus::Rejected, $reject->fresh()->status);
        $this->assertSame(0, $carla->households()->count());
        $this->assertSame(1, Household::query()->count());
    }
}

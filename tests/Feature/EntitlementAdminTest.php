<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\FeaturesRelationManager;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\FeatureGrantsRelationManager;
use App\Filament\Resources\Users\RelationManagers\ServiceAccountsRelationManager;
use App\Filament\Resources\Users\RelationManagers\SubscriptionsRelationManager;
use App\Models\Feature;
use App\Models\FeatureGrant;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\Suspended;
use App\Notifications\SuspensionLifted;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class EntitlementAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $shopping;

    private Feature $ai;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        $this->shopping = Service::factory()->create(['key' => 'shoppinglist', 'name' => 'Shoppinglist']);
        $this->ai = Feature::factory()->for($this->shopping)->create(['key' => 'shoppinglist.ai', 'name' => 'KI-Wochenplanung']);
    }

    private function user(): User
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-anna']);
        ServiceAccount::factory()->for($user)->for($this->shopping)->create();

        return $user;
    }

    public function test_admins_manage_the_features_of_a_service(): void
    {
        Livewire::test(EditService::class, ['record' => $this->shopping->getRouteKey()])
            ->fillForm(['admin_group' => 'svc-shoppinglist-admin'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('svc-shoppinglist-admin', $this->shopping->fresh()?->admin_group);

        $manager = fn () => Livewire::test(FeaturesRelationManager::class, ['ownerRecord' => $this->shopping, 'pageClass' => EditService::class]);
        $manager()->assertOk()->assertSee('shoppinglist.ai');

        $manager()->callAction(TestAction::make('create')->table(), ['name' => 'Export', 'key' => 'Shopping List!'])
            ->assertHasFormErrors(['key' => 'regex']);
        $manager()->callAction(TestAction::make('create')->table(), ['name' => 'Export', 'key' => 'shoppinglist.ai'])
            ->assertHasFormErrors(['key' => 'unique']);
        $manager()->callAction(TestAction::make('create')->table(), ['name' => 'Export', 'key' => 'shoppinglist.export', 'group' => 'feat-shoppinglist-export'])
            ->assertHasNoFormErrors();

        $this->assertTrue($this->shopping->features()->where('key', 'shoppinglist.export')->where('group', 'feat-shoppinglist-export')->exists());
    }

    public function test_admins_create_and_edit_plans(): void
    {
        $other = Feature::factory()->create(['key' => 'recipes.cookbooks']);

        Livewire::test(CreatePlan::class)
            ->fillForm(['name' => 'Shoppinglist Plus', 'service_id' => $this->shopping->id, 'features' => [$this->ai->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::query()->sole();
        $this->assertSame([$this->ai->id], $plan->features()->pluck('features.id')->all());

        // A bundle may combine features of several services.
        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['service_id' => null, 'features' => [$this->ai->id, $other->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertCount(2, $plan->features()->get());

        Livewire::test(ListPlans::class)->assertOk()->assertCanSeeTableRecords([$plan])->assertSee('Shoppinglist Plus');

        // Plans with subscriptions cannot be deleted.
        Subscription::factory()->for($this->user())->for($plan)->create();
        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])->assertActionHidden('delete');
    }

    public function test_admins_assign_end_suspend_and_reactivate_plans(): void
    {
        $user = $this->user();
        $plan = Plan::factory()->create(['name' => 'Shoppinglist Plus', 'service_id' => $this->shopping->id]);
        $plan->features()->attach($this->ai);
        $inactive = Plan::factory()->create(['active' => false]);
        $manager = fn () => Livewire::test(SubscriptionsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);

        $manager()->callAction(TestAction::make('create')->table(), ['plan_id' => $inactive->id, 'starts_at' => now()->toDateTimeString()])
            ->assertHasFormErrors(['plan_id']);
        $manager()->callAction(TestAction::make('create')->table(), ['plan_id' => $plan->id, 'starts_at' => now()->subMinute()->toDateTimeString()])
            ->assertHasNoFormErrors();

        $subscription = Subscription::query()->sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($this->admin->id, $subscription->created_by);
        $this->assertTrue($subscription->isEffective());

        $manager()->callAction(TestAction::make('suspend')->table($subscription), ['reason' => 'Offene Rechnung']);
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->fresh()?->status);
        Notification::assertSentTo($user, Suspended::class, function (Suspended $notification) use ($user): bool {
            $mail = $notification->toMail($user)->render();

            return str_contains((string) $mail, 'Offene Rechnung') && str_contains((string) $mail, 'Shoppinglist Plus');
        });

        $manager()->callAction(TestAction::make('resume')->table($subscription), ['message' => 'Danke für die Zahlung.']);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        Notification::assertSentTo($user, SuspensionLifted::class, fn (SuspensionLifted $notification): bool => str_contains((string) $notification->toMail($user)->render(), 'Danke für die Zahlung.'));
        $this->assertNotNull(Suspension::query()->sole()->lifted_at);

        $manager()->callAction(TestAction::make('end')->table($subscription));
        $this->assertSame(SubscriptionStatus::Ended, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->ends_at);
    }

    public function test_admins_grant_and_revoke_features(): void
    {
        $user = $this->user();
        $manager = fn () => Livewire::test(FeatureGrantsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);

        $manager()->callAction(TestAction::make('create')->table(), ['feature_id' => $this->ai->id, 'note' => 'Testerin'])->assertHasNoFormErrors();
        $grant = FeatureGrant::query()->sole();
        $this->assertSame($this->admin->id, $grant->granted_by);
        $manager()->callAction(TestAction::make('create')->table(), ['feature_id' => $this->ai->id])->assertHasFormErrors(['feature_id' => 'unique']);

        $manager()->assertSee('KI-Wochenplanung')->callAction(TestAction::make('delete')->table($grant));
        $this->assertSame(0, FeatureGrant::query()->count());
    }

    public function test_suspending_an_account_mails_the_reason_and_reactivating_mails_too(): void
    {
        $user = $this->user();
        $account = $user->serviceAccounts()->sole();
        $manager = fn () => Livewire::test(ServiceAccountsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);

        $manager()->callAction(TestAction::make('suspend')->table($account), ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        $manager()->callAction(TestAction::make('suspend')->table($account), ['reason' => 'Missbrauch der KI-Funktion']);

        $this->assertSame(AccountStatus::Suspended, $account->fresh()?->status);
        $suspension = Suspension::query()->sole();
        $this->assertSame($account->id, $suspension->service_account_id);
        $this->assertSame($this->admin->id, $suspension->suspended_by);
        Notification::assertSentTo($user, Suspended::class, function (Suspended $notification) use ($user): bool {
            $mail = (string) $notification->toMail($user)->render();

            return str_contains($mail, 'Missbrauch der KI-Funktion') && str_contains($mail, 'Shoppinglist');
        });

        $manager()->assertActionHidden(TestAction::make('approve')->table($account->fresh()))
            ->callAction(TestAction::make('reactivate')->table($account->fresh()), ['message' => '']);

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        $this->assertNotNull($suspension->fresh()?->lifted_at);
        Notification::assertSentTo($user, SuspensionLifted::class);
    }

    public function test_the_user_page_shows_the_effective_entitlements(): void
    {
        $user = $this->user();
        $user->serviceAccounts()->sole()->update(['is_admin' => true]);
        FeatureGrant::query()->create(['user_id' => $user->id, 'feature_id' => $this->ai->id]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertOk()
            ->assertSee('Effective entitlements')
            ->assertSee('Shoppinglist (Active, Admin): shoppinglist.ai');

        Livewire::test(SubscriptionsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])->assertOk();
    }
}

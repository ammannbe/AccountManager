<?php

namespace Tests\Feature;

use App\Enums\HouseholdRole;
use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Filament\Resources\Households\Pages\ViewHousehold;
use App\Filament\Resources\Households\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Households\RelationManagers\SharesRelationManager;
use App\Households\Households;
use App\Households\LastHouseholdAdmin;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Service;
use App\Models\User;
use App\Notifications\HouseholdInvited;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('households.index'))->assertRedirect(route('login'));
    }

    public function test_a_user_creates_a_household_and_becomes_its_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('households.store'), ['name' => '  Familie Muster '])->assertRedirect(route('households.index'));

        $household = Household::query()->sole();
        $this->assertSame('Familie Muster', $household->name);
        $this->assertSame(HouseholdRole::Admin, $household->roleOf($user));
        $this->assertSame(1, $household->version);
        $this->assertTrue($household->creator->is($user));
    }

    public function test_the_page_lists_households_with_members_shares_and_invitations(): void
    {
        $admin = User::factory()->create(['name' => 'Anna']);
        $member = User::factory()->create(['name' => 'Ben']);
        $household = Household::factory()->withAdmin($admin)->withMember($member)->create(['name' => 'Familie Muster']);
        HouseholdInvitation::factory()->for($household)->create(['email' => 'carla@example.com']);
        Household::factory()->create(['name' => 'Someone else']);

        $this->actingAs($admin)->get(route('households.index'))
            ->assertOk()
            ->assertSee('Familie Muster')
            ->assertSee('Ben')
            ->assertSee('carla@example.com')
            ->assertSee('Invite someone')
            ->assertDontSee('Someone else');

        // Members see the household but cannot manage it.
        $this->actingAs($member)->get(route('households.index'))
            ->assertOk()
            ->assertSee('Familie Muster')
            ->assertDontSee('carla@example.com')
            ->assertDontSee('Invite someone');
    }

    public function test_admins_rename_and_delete(): void
    {
        $admin = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->create();

        $this->actingAs($admin)->put(route('households.update', $household), ['name' => 'Neu'])->assertRedirect(route('households.index'));
        $this->assertSame('Neu', $household->fresh()->name);
        $this->assertSame(2, $household->fresh()->version);

        $this->actingAs($admin)->delete(route('households.destroy', $household))->assertRedirect(route('households.index'));
        $this->assertModelMissing($household);
    }

    public function test_members_cannot_manage_and_outsiders_do_not_see_the_household(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->withMember($member)->create(['name' => 'Alt']);

        $this->actingAs($member)->put(route('households.update', $household), ['name' => 'Neu'])->assertForbidden();
        $this->actingAs($member)->delete(route('households.destroy', $household))->assertForbidden();
        $this->actingAs($member)->post(route('households.invitations.store', $household), ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
        $this->actingAs($member)->delete(route('households.members.destroy', [$household, $admin]))->assertForbidden();
        $this->actingAs($outsider)->put(route('households.update', $household), ['name' => 'Neu'])->assertNotFound();
        $this->actingAs($outsider)->delete(route('households.members.destroy', [$household, $outsider]))->assertNotFound();

        $this->assertSame('Alt', $household->fresh()->name);
    }

    public function test_admins_invite_by_email_with_a_role(): void
    {
        $admin = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->create();

        $this->actingAs($admin)
            ->post(route('households.invitations.store', $household), ['email' => ' Carla@Example.com ', 'role' => 'admin'])
            ->assertRedirect(route('households.index'))
            ->assertSessionHas('status');

        $invitation = HouseholdInvitation::query()->sole();
        $this->assertSame('carla@example.com', $invitation->email);
        $this->assertSame(HouseholdRole::Admin, $invitation->role);
        $this->assertTrue($invitation->inviter->is($admin));
        $this->assertSame(64, strlen($invitation->token_hash));

        Notification::assertSentTo(new AnonymousNotifiable, HouseholdInvited::class, function (HouseholdInvited $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation): bool {
            // Only the hash is stored; the mail carries the token.
            return $notifiable->routes['mail'] === 'carla@example.com'
                && HouseholdInvitation::hash($notification->token) === $invitation->token_hash
                && str_contains($notification->toMail($notifiable)->actionUrl, $notification->token);
        });
    }

    public function test_inviting_again_replaces_the_token_and_members_are_not_invited(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create(['email' => 'ben@example.com']);
        $household = Household::factory()->withAdmin($admin)->withMember($member)->create();

        $this->actingAs($admin)->post(route('households.invitations.store', $household), ['email' => 'carla@example.com', 'role' => 'member']);
        $first = HouseholdInvitation::query()->sole()->token_hash;
        $this->actingAs($admin)->post(route('households.invitations.store', $household), ['email' => 'carla@example.com', 'role' => 'member']);
        $this->assertNotSame($first, HouseholdInvitation::query()->sole()->token_hash);

        $this->actingAs($admin)->post(route('households.invitations.store', $household), ['email' => 'BEN@example.com', 'role' => 'member'])
            ->assertSessionHasErrors('household');
        $this->assertSame(1, HouseholdInvitation::query()->count());
    }

    public function test_admins_revoke_invitations_of_their_household_only(): void
    {
        $admin = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->create();
        $invitation = HouseholdInvitation::factory()->for($household)->create();
        $foreign = HouseholdInvitation::factory()->create();

        $this->actingAs($admin)->delete(route('households.invitations.destroy', [$household, $foreign]))->assertNotFound();
        $this->actingAs($admin)->delete(route('households.invitations.destroy', [$household, $invitation]))->assertRedirect(route('households.index'));

        $this->assertModelMissing($invitation);
        $this->assertModelExists($foreign);
    }

    public function test_admins_change_roles_and_remove_members(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->withMember($member)->create();

        $this->actingAs($admin)->put(route('households.members.update', [$household, $member]), ['role' => 'admin']);
        $this->assertSame(HouseholdRole::Admin, $household->roleOf($member));

        $this->actingAs($admin)->delete(route('households.members.destroy', [$household, $member]))->assertSessionHas('status', 'Member removed.');
        $this->assertFalse($household->hasMember($member));
        $this->assertSame(3, $household->fresh()->version);
    }

    public function test_the_last_admin_can_neither_leave_nor_be_demoted_or_removed(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->withMember($member)->create();

        $this->actingAs($admin)->delete(route('households.members.destroy', [$household, $admin]))->assertSessionHasErrors('household');
        $this->assertTrue($household->hasMember($admin));

        $this->actingAs($admin)->put(route('households.members.update', [$household, $admin]), ['role' => 'member'])->assertSessionHasErrors('household');
        $this->assertSame(HouseholdRole::Admin, $household->roleOf($admin));

        $this->expectException(LastHouseholdAdmin::class);
        app(Households::class)->removeMember($household, $admin);
    }

    public function test_an_admin_can_leave_once_another_admin_exists_and_members_leave(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $member = User::factory()->create();
        $household = Household::factory()->withAdmin($admin)->withMember($other, HouseholdRole::Admin)->withMember($member)->create();

        $this->actingAs($member)->delete(route('households.members.destroy', [$household, $member]))->assertSessionHas('status', 'You left the household.');
        $this->actingAs($admin)->delete(route('households.members.destroy', [$household, $admin]))->assertSessionHas('status', 'You left the household.');

        $this->assertSame([$other->id], $household->members()->pluck('user_id')->all());
    }

    public function test_deleting_a_user_hands_households_over_and_deletes_empty_ones(): void
    {
        $user = User::factory()->create();
        $older = User::factory()->create();
        $newer = User::factory()->create();
        $shared = Household::factory()->withAdmin($user)->create();
        $shared->members()->create(['user_id' => $newer->id, 'role' => HouseholdRole::Member, 'joined_at' => now()]);
        $shared->members()->create(['user_id' => $older->id, 'role' => HouseholdRole::Member, 'joined_at' => now()->subYear()]);
        $alone = Household::factory()->withAdmin($user)->create();
        $withAdmin = Household::factory()->withMember($user)->withAdmin($newer)->create();

        $user->delete();

        $this->assertModelMissing($alone);
        $this->assertSame(HouseholdRole::Admin, $shared->roleOf($older));
        $this->assertSame(HouseholdRole::Member, $shared->roleOf($newer));
        $this->assertSame(2, $shared->fresh()->version);
        $this->assertSame([$newer->id], $withAdmin->members()->pluck('user_id')->all());
        $this->assertSame(HouseholdRole::Admin, $withAdmin->roleOf($newer));
        $this->assertNull($shared->fresh()->created_by);
    }

    public function test_admins_see_a_read_only_overview_in_the_panel(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['name' => 'Anna Muster']);
        $household = Household::factory()->withAdmin($member)->create(['name' => 'Familie Muster']);
        $household->shares()->create(['service_id' => Service::factory()->create()->id, 'resource_type' => 'cookbook', 'resource_id' => '1', 'label' => 'Familienrezepte']);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListHouseholds::class)->assertOk()->assertCanSeeTableRecords([$household])->assertSee('Anna Muster');
        Livewire::test(ViewHousehold::class, ['record' => $household->getRouteKey()])->assertOk()->assertSee('Familie Muster');
        Livewire::test(SharesRelationManager::class, ['ownerRecord' => $household, 'pageClass' => ViewHousehold::class])->assertOk()->assertSee('Familienrezepte');
        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $household, 'pageClass' => ViewHousehold::class])->assertOk()->assertSee('Anna Muster');
        $this->get("/admin/households/{$household->uuid}/edit")->assertNotFound();
    }
}

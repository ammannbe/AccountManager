<?php

namespace Tests\Feature;

use App\Identity\AdminUrl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PocketIdAdminLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_see_the_link_with_pocket_id_and_a_browser_url(): void
    {
        config([
            'identity.driver' => 'pocket-id',
            'identity.admin_url' => null,
            'identity.account_url' => 'https://login.example.com/settings/account',
        ]);

        $this->assertSame('https://login.example.com/settings/admin/users', AdminUrl::get());

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Manage Pocket ID')
            ->assertSee('https://login.example.com/settings/admin/users', false);
    }

    public function test_the_configured_admin_url_wins(): void
    {
        config([
            'identity.driver' => 'pocket-id',
            'identity.admin_url' => 'https://id.example.com/settings/admin',
            'identity.account_url' => 'https://login.example.com/settings/account',
        ]);

        $this->assertSame('https://id.example.com/settings/admin', AdminUrl::get());
    }

    public function test_the_link_is_hidden_without_pocket_id_or_url(): void
    {
        $admin = User::factory()->admin()->create();

        config(['identity.driver' => 'manual', 'identity.admin_url' => 'https://id.example.com/settings/admin']);
        $this->assertNull(AdminUrl::get());
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Manage Pocket ID');

        config(['identity.driver' => 'pocket-id', 'identity.admin_url' => null, 'identity.account_url' => null]);
        $this->assertNull(AdminUrl::get());
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Manage Pocket ID');
    }
}

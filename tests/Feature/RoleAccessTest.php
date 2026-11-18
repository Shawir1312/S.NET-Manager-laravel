<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GenieAcsSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_genieacs_settings(): void
    {
        $this->seed(GenieAcsSettingSeeder::class);

        $this->actingAs(User::factory()->admin()->create())->get('/settings/genieacs')->assertOk();
        $this->actingAs(User::factory()->teknisi()->create())->get('/settings/genieacs')->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get('/settings/genieacs')->assertForbidden();
    }

    public function test_admin_menu_hidden_for_non_admin(): void
    {
        $this->actingAs(User::factory()->viewer()->create())->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Config GenieACS')
            ->assertDontSee('Kelola Admin')
            ->assertSee('Daftar ONT');

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')
            ->assertSee('Config GenieACS')
            ->assertSee('Kelola Admin')
            ->assertSee('Config ONT Manual');
    }

    public function test_gates_follow_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->teknisi()->create();
        $viewer = User::factory()->viewer()->create();

        $this->assertTrue($admin->can('configure-ont'));
        $this->assertTrue($teknisi->can('configure-ont'));
        $this->assertFalse($viewer->can('configure-ont'));

        $this->assertTrue($admin->can('manage-settings'));
        $this->assertFalse($teknisi->can('manage-settings'));
        $this->assertTrue($viewer->can('view-devices'));
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/stats')->assertUnauthorized();
        $this->postJson('/api/genieacs/test-connection')->assertUnauthorized();
    }

    public function test_test_connection_api_is_admin_only(): void
    {
        $this->actingAs(User::factory()->teknisi()->create())
            ->postJson('/api/genieacs/test-connection')->assertForbidden();
    }

    public function test_security_headers_present(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}

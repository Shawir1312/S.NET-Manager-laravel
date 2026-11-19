<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class RouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_test_returns_json_status_and_radius_sync_rewrites_nas(): void
    {
        $fake = new FakeMikrotik;
        $this->app->instance(MikrotikConnector::class, $fake);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/routers', ['name' => 'R1', 'ip_address' => '10.0.0.1', 'nas_ip' => '0.0.0.0/0', 'api_user' => 'admin',
            'api_password' => 'x', 'api_port' => 8728, 'radius_secret' => 'sekret', 'status' => 'active'])->assertRedirect();
        $router = Router::firstOrFail();

        $this->actingAs($admin)->postJson("/routers/{$router->id}/test")->assertJson(['online' => true, 'identity' => 'MT-TEST']);
        $fake->offline = true;
        $this->actingAs($admin)->postJson("/routers/{$router->id}/test")->assertJson(['online' => false]);

        DB::table('nas')->delete();
        $this->actingAs($admin)->post('/routers/sync-radius')->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('nas', ['nasname' => '0.0.0.0/0', 'secret' => 'sekret']);
    }
}

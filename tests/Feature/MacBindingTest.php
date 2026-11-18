<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class MacBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_merges_host_lease_and_queue_and_add_creates_binding_with_queue(): void
    {
        $fake = new FakeMikrotik;
        $fake->responses = [
            '/ip/hotspot/ip-binding/print' => [['.id' => '*1', 'mac-address' => 'AA:BB:CC:DD:EE:01', 'type' => 'bypassed', 'comment' => 'Rumah Budi', 'disabled' => 'false']],
            '/ip/dhcp-server/lease/print' => [['.id' => '*A', 'mac-address' => 'AA:BB:CC:DD:EE:01', 'address' => '10.5.0.9', 'dynamic' => 'false', 'host-name' => 'hp-budi'],
                ['.id' => '*B', 'mac-address' => 'AA:BB:CC:DD:EE:02', 'address' => '10.5.0.10', 'status' => 'bound', 'dynamic' => 'true']],
            '/ip/hotspot/host/print' => [['mac-address' => 'AA:BB:CC:DD:EE:01', 'address' => '10.5.0.9', 'uptime' => '1h', 'bytes-in' => '2048']],
            '/queue/simple/print' => [['.id' => '*Q', 'name' => 'Rumah Budi', 'target' => '10.5.0.9/32', 'max-limit' => '5M/5M']],
        ];
        $this->app->instance(MikrotikConnector::class, $fake);
        $router = Router::create(['name' => 'R1', 'ip_address' => '10.0.0.1', 'api_user' => 'a', 'api_password' => 'b', 'api_port' => 8728, 'radius_secret' => 's', 'status' => 'active']);
        $user = User::factory()->teknisi()->create();

        $this->actingAs($user)->get('/monitoring/mac?router_id='.$router->id)->assertOk()->assertSee('Daftarkan MAC Baru');
        $this->actingAs($user)->getJson("/monitoring/mac/api?action=list&router_id={$router->id}")->assertOk()
            ->assertJsonPath('data.0.is_online', true)->assertJsonPath('data.0.is_static', true)
            ->assertJsonPath('data.0.limit', '5M/5M')->assertJsonPath('data.0.host_name', 'hp-budi');

        $this->actingAs($user)->postJson('/monitoring/mac/api', ['action' => 'add', 'router_id' => $router->id, 'mac' => 'AA:BB:CC:DD:EE:01', 'name' => 'X'])
            ->assertJsonPath('success', false);
        $this->actingAs($user)->postJson('/monitoring/mac/api', ['action' => 'add', 'router_id' => $router->id, 'mac' => 'aa-bb-cc-dd-ee-02', 'name' => 'Warung', 'limit' => '2M/2M'])
            ->assertJsonPath('success', true);

        $cmds = collect($fake->commands);
        $this->assertTrue($cmds->contains(fn ($c) => $c[0] === '/ip/hotspot/ip-binding/add' && $c[1]['mac-address'] === 'AA:BB:CC:DD:EE:02'));
        $this->assertTrue($cmds->contains(fn ($c) => $c[0] === '/ip/dhcp-server/lease/make-static' && $c[1]['.id'] === '*B'));
        $this->assertTrue($cmds->contains(fn ($c) => $c[0] === '/queue/simple/add' && $c[1]['target'] === '10.5.0.10/32' && $c[1]['max-limit'] === '2M/2M'));

        $this->actingAs($user)->get("/monitoring/mac/api?action=delete&router_id={$router->id}&id=*1")->assertStatus(405);
    }
}

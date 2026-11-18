<?php

namespace Tests\Feature;

use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiGenieServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_second_server_and_customer_uses_router_server(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['connect_timeout' => 5, 'request_timeout' => 30, 'connection_request_timeout' => 10, 'verify_tls' => 1];

        $this->actingAs($admin)->put('/settings/genieacs', $base + ['name' => 'ACS Utama', 'nbi_url' => 'http://acs-a.test:7557'])->assertRedirect();
        $this->actingAs($admin)->put('/settings/genieacs', $base + ['name' => 'ACS Cabang', 'nbi_url' => 'http://acs-b.test:7557', 'new_server' => 1])->assertRedirect();
        $this->assertSame(2, GenieAcsSetting::count());
        $b = GenieAcsSetting::where('name', 'ACS Cabang')->firstOrFail();

        $router = Router::factory()->create(['genie_acs_setting_id' => $b->id]);
        $c = PppoeCustomer::factory()->for($router)->create(['ont_sn' => 'ZTEGC0FFEE01']);
        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($admin)->post("/customers/{$c->id}/push-ont", ['service' => 'pppoe'])->assertSessionHasErrors('ont');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://acs-b.test:7557/devices'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'acs-a.test'));
    }

    public function test_server_list_and_delete_keeps_last_server(): void
    {
        $admin = User::factory()->admin()->create();
        $a = GenieAcsSetting::create(['name' => 'ACS A', 'nbi_url' => 'http://a.test:7557', 'is_active' => true]);
        $b = GenieAcsSetting::create(['name' => 'ACS B', 'nbi_url' => 'http://b.test:7557', 'is_active' => true]);
        OntDevice::create(['genie_acs_setting_id' => $b->id, 'genieacs_id' => 'D1', 'serial_number' => 'SN1']);

        $this->actingAs($admin)->get('/settings/genieacs')->assertOk()->assertSee('ACS A')->assertSee('ACS B')->assertSee('2 Server');
        $this->actingAs($admin)->delete("/settings/genieacs/{$b->id}")->assertRedirect();
        $this->assertModelMissing($b);
        $this->assertNull(OntDevice::first()->genie_acs_setting_id);
        $this->actingAs($admin)->delete("/settings/genieacs/{$a->id}")->assertSessionHasErrors('server');
        $this->assertModelExists($a);
    }
}

<?php

namespace Tests\Feature;

use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Models\OntRemote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OntMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_lists_server_devices_and_remote_json_flow(): void
    {
        $server = GenieAcsSetting::create(['name' => 'ACS-1', 'nbi_url' => 'http://127.0.0.1:7557', 'is_active' => true]);
        $device = OntDevice::create(['genie_acs_setting_id' => $server->id, 'genieacs_id' => 'X-1', 'serial_number' => 'ZTEG00000001',
            'product_class' => 'F670L', 'ip_address' => '10.20.0.5', 'last_inform_at' => now(), 'parameters_snapshot' => ['ssid_24' => 'RUMAH-BUDI', 'rx' => '-28.1']]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/ont-monitor')->assertOk()
            ->assertSee('ZTEG00000001')->assertSee('RUMAH-BUDI')->assertSee('text-danger fw-bold', false)->assertSee('Online');

        $res = $this->actingAs($admin)->postJson("/ont-monitor/{$device->id}/remote")->assertJsonPath('success', true);
        $this->assertGreaterThan(0, $res->json('remaining_seconds'));
        $this->actingAs($admin)->get('/ont-monitor')->assertSee('Akses Remote ONT Sedang Aktif');

        $this->actingAs($admin)->deleteJson('/ont-monitor/remote-all')->assertJsonPath('success', true);
        $this->assertFalse(OntRemote::firstOrFail()->is_active);
    }
}

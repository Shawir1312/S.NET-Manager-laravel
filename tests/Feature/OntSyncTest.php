<?php

namespace Tests\Feature;

use App\Models\ConfigurationJob;
use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Models\User;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\OntLiveInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OntSyncTest extends TestCase
{
    use RefreshDatabase;

    private function doc(string $sn, array $extra = []): array
    {
        return array_replace_recursive([
            '_id' => "OUI-F670L-{$sn}",
            '_deviceId' => ['_SerialNumber' => $sn, '_Manufacturer' => 'ZTE', '_OUI' => 'C4741E', '_ProductClass' => 'F670L'],
            '_lastInform' => now()->toIso8601String(),
        ], $extra);
    }

    public function test_live_info_reads_ip_ssid_and_normalises_optical_units(): void
    {
        $tree = ParameterTree::fromDocument($this->doc('ZTEG00000001', ['InternetGatewayDevice' => [
            'WANDevice' => ['1' => [
                'WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => ['ExternalIPAddress' => ['_value' => '10.20.30.44']]]]],
                'X_ZTE-COM_WANPONInterfaceConfig' => ['RXPower' => ['_value' => '-215'], 'TXPower' => ['_value' => '22']],
            ]],
            'LANDevice' => ['1' => ['WLANConfiguration' => ['1' => ['SSID' => ['_value' => 'RUMAH-BUDI']], '5' => ['SSID' => ['_value' => 'RUMAH-BUDI-5G']]]]],
        ]]));

        $this->assertSame(['ip_address' => '10.20.30.44', 'ssid_24' => 'RUMAH-BUDI', 'ssid_5g' => 'RUMAH-BUDI-5G', 'rx' => '-21.5', 'tx' => '-2.2'], OntLiveInfo::fromTree($tree));
        $this->assertSame(-27.0, OntLiveInfo::toDbm('1995.26')); // satuan µW (Huawei) → dBm
        $this->assertSame(-19.5, OntLiveInfo::toDbm('-19.5'));
        $this->assertNull(OntLiveInfo::toDbm('0'));
    }

    public function test_sync_after_switching_acs_removes_old_onts_and_keeps_ones_with_history(): void
    {
        $server = GenieAcsSetting::create(['name' => 'ACS', 'nbi_url' => 'http://acs-baru.test:7557', 'is_active' => true]);
        $old = OntDevice::create(['genie_acs_setting_id' => $server->id, 'genieacs_id' => 'LAMA-1', 'serial_number' => 'OLDSN0000001', 'synced_at' => now()->subDay()]);
        $withHistory = OntDevice::create(['genie_acs_setting_id' => $server->id, 'genieacs_id' => 'LAMA-2', 'serial_number' => 'OLDSN0000002', 'synced_at' => now()->subDay()]);
        ConfigurationJob::forceCreate(['uuid' => (string) Str::uuid(), 'ont_device_id' => $withHistory->id, 'service_type' => 'pppoe', 'request_payload' => '{}', 'status' => 'success', 'idempotency_key' => 'k1']);

        Http::fake(['acs-baru.test:7557/devices/*' => Http::response([$this->doc('ZTEGNEW00001', ['_tags' => ['Warung Ani'], 'VirtualParameters' => ['RXPower' => ['_value' => '-18.2']]])])]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/ont-monitor/sync?server_id='.$server->id)->assertJsonPath('success', true);

        $this->assertModelMissing($old);
        $this->assertNull($withHistory->fresh()->genie_acs_setting_id, 'ONT berriwayat dilepas dari server, tidak dihapus');
        $new = OntDevice::where('serial_number', 'ZTEGNEW00001')->firstOrFail();
        $this->assertSame('-18.2', $new->parameters_snapshot['rx']);
        $this->actingAs($admin)->get('/ont-monitor')->assertOk()->assertSee('ZTEGNEW00001')->assertDontSee('OLDSN0000001')->assertSee('Sinkron')
            ->assertSee('title="Warung Ani"', false)->assertSee('#Warung Ani');
    }
}

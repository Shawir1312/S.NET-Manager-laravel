<?php

namespace Tests\Feature;

use App\Enums\ModelSupportStatus;
use App\Models\ConfigurationJob;
use App\Models\OntDevice;
use App\Models\OntModel;
use App\Models\User;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakesGenieAcs;
use Tests\Support\StatefulGenieAcs;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    private User $tech;

    private OntDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OntModelSeeder::class);
        $this->useFakeAcsSetting();
        config(['ontconfig.task_poll_interval_ms' => 0, 'ontconfig.task_timeout_seconds' => 1]);
        $this->tech = User::factory()->teknisi()->create();
        $this->device = OntDevice::create(['genieacs_id' => '000AC2-HG6145D2-FHTT9D34CF80', 'serial_number' => 'FHTT9D34CF80']);
    }

    private function input(array $override = []): array
    {
        return array_replace_recursive([
            'device_id' => $this->device->id,
            'idempotency_key' => (string) Str::uuid(),
            'service_type' => 'bridge',
            'wan' => ['mode' => 'new', 'slot' => 'ip', 'vlan_id' => 300, 'vlan_priority' => 3, 'service_list' => 'INTERNET'],
            'ssids' => [['index' => 1, 'ssid_name' => 'WIFI-RUMAH', 'security' => '11i', 'password' => 'wifiaman123']],
            'binding' => ['lan' => [1, 2], 'wlan' => [1]],
        ], $override);
    }

    private function enableProduction(): void
    {
        config(['ontconfig.provisioning_mode' => 'production']);
        $model = OntModel::where('product_class', 'HG6145D2')->first();
        $model->update(['status' => ModelSupportStatus::Supported]);
        $model->parameterMappings()->update(['verified' => true]);
    }

    public function test_simulation_end_to_end_via_api(): void
    {
        $sim = (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();

        $preview = $this->actingAs($this->tech)->postJson("/api/ont/{$this->device->id}/preview", $this->input())
            ->assertOk()->assertJsonPath('mode', 'simulation')->assertJsonPath('executable', true)->json();
        $this->assertStringNotContainsString('wifiaman123', json_encode($preview));
        $this->assertNotEmpty($preview['production_blockers']);

        $input = $this->input();
        $res = $this->actingAs($this->tech)->postJson('/api/ont/configure', $input)->assertStatus(202)->assertJsonPath('is_simulation', true);
        $id = $res->json('id');

        $status = $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")->assertOk()->json();
        $this->assertSame('success', $status['status']);
        $this->assertTrue($status['can_rollback']);
        $this->assertStringContainsString('SIMULASI', collect($status['timeline'])->pluck('message')->join(' '));

        // Tidak ada task tulis yang dikirim ke "ONT"
        $this->assertSame([], array_values(array_filter($sim->tasks, fn ($t) => in_array($t['name'], ['addObject', 'setParameterValues'], true))));

        // Password dihapus dari payload setelah selesai, dan tidak ada di DB dalam bentuk teks
        $job = ConfigurationJob::where('uuid', $id)->first();
        $this->assertNull($job->request_payload['ssids'][0]['password']);
        $this->assertStringNotContainsString('wifiaman123', json_encode(DB::table('configuration_backups')->get()));
        $this->assertStringNotContainsString('wifiaman123', json_encode(DB::table('configuration_histories')->get()));

        // APPLY ditekan dua kali -> job yang sama
        $this->actingAs($this->tech)->postJson('/api/ont/configure', $input)->assertStatus(202)->assertJsonPath('id', $id);
        $this->assertSame(1, ConfigurationJob::count());

        $this->actingAs($this->tech)->postJson("/api/configuration/{$id}/rollback")->assertOk()->assertJsonPath('status', 'simulated');
        $this->actingAs($this->tech)->postJson("/api/configuration/{$id}/rollback")->assertStatus(422);
    }

    public function test_invalid_configuration_is_rejected_with_reason(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();

        $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input(['wan' => ['vlan_id' => 100]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['message' => 'Konfigurasi tidak valid: VLAN 100 sudah dipakai oleh WAN "1_TR069_VOIP_R_VID_100" (WANConnectionDevice.1.WANIPConnection.1). Pilih "Gunakan WAN yang ada" bila ingin mengubahnya.']);

        $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input(['wan' => ['vlan_id' => 5000]]))->assertUnprocessable();
        $this->actingAs(User::factory()->viewer()->create())->postJson('/api/ont/configure', $this->input())->assertForbidden();
    }

    public function test_production_refuses_unverified_model(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        config(['ontconfig.provisioning_mode' => 'production']);

        $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())
            ->assertStatus(422)->assertJsonPath('error_code', 'NOT_VERIFIED');
        $this->assertSame(0, ConfigurationJob::count());
    }

    public function test_production_applies_verifies_and_rolls_back(): void
    {
        $this->enableProduction();
        $sim = (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();

        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())->assertStatus(202)->json('id');
        $status = $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")->json();

        $this->assertSame('success', $status['status'], json_encode($status['timeline']));
        $this->assertFalse($status['is_simulation']);

        $wcd = $sim->doc['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'];
        $this->assertArrayHasKey('2', $wcd, 'WANConnectionDevice baru dibuat');
        $wan = $wcd['2']['WANIPConnection']['1'];
        $this->assertSame('IP_Bridged', $wan['ConnectionType']['_value'][0]);
        $this->assertSame(300, $wan['VLANID']['_value'][0]);
        $this->assertSame(3, $wcd['2']['X_FH_WANGponLinkConfig']['802-1pMark']['_value'][0]);
        $this->assertStringStartsWith('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,', $wan['X_FH_LanInterface']['_value'][0]);
        $this->assertSame('WIFI-RUMAH', $sim->doc['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['1']['SSID']['_value'][0]);

        $job = ConfigurationJob::where('uuid', $id)->first();
        $this->assertSame('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1', $job->wanConfigurations->first()->instance_path);
        $this->assertTrue(collect($status['verification'])->every(fn ($r) => in_array($r['status'], ['match', 'secret'], true)));
        $this->assertNull($job->request_payload['wan']['pppoe_password']);

        // Parameter yang terbukti tertulis & cocok otomatis naik ke level "write"
        $mapping = OntModel::where('product_class', 'HG6145D2')->first()->parameterMappings()->where('key', 'wan.ip.vlan_id')->first();
        $this->assertSame('write', $mapping->verification_level);
        $this->assertSame('FHTT9D34CF80', $mapping->verified_device);

        // Rollback: SSID kembali, WAN baru dihapus
        $this->actingAs($this->tech)->postJson("/api/configuration/{$id}/rollback")->assertOk()->assertJsonPath('status', 'complete');
        $this->assertSame('fh_34cf80', $sim->doc['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['1']['SSID']['_value'][0]);
        $this->assertArrayNotHasKey('2', $sim->doc['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']);
    }

    public function test_production_offline_device_is_not_reported_successful(): void
    {
        $this->enableProduction();
        $sim = (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $sim->online = false;

        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())->json('id');
        $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")
            ->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'DEVICE_OFFLINE');
        $this->assertSame([], $sim->pending, 'Task cek online yang tertunda harus dihapus');
    }

    public function test_production_fault_fails_job_with_reason(): void
    {
        $this->enableProduction();
        $sim = (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $sim->faultOn = 'setParameterValues';

        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())->json('id');
        $status = $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")->json();

        $this->assertSame('failed', $status['status']);
        $this->assertSame('SET_VALUES_FAILED', $status['error_code']);
        $this->assertStringContainsString('cwmp.9007', $status['error_message']);
        $this->assertTrue($status['can_rollback'], 'WAN yang sudah dibuat bisa di-rollback');
    }

    public function test_production_verification_mismatch_is_partial(): void
    {
        $this->enableProduction();
        $sim = (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $sim->ignorePaths = ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'];

        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())->json('id');
        $status = $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")->json();

        $this->assertSame('partial', $status['status']);
        $this->assertSame('VERIFY_MISMATCH', $status['error_code']);
        $this->assertSame('mismatch', collect($status['verification'])->firstWhere('path', 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID')['status']);
    }

    public function test_rollback_after_early_failure_does_not_touch_ont(): void
    {
        $this->enableProduction();
        $sim = (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $sim->faultOn = 'addObject';

        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())->json('id');
        $status = $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")->json();
        $this->assertSame('ADD_OBJECT_FAILED', $status['error_code']);
        $this->assertFalse($status['can_rollback'], 'Tidak ada yang diterapkan, jadi tidak ada yang di-rollback');

        $tasksBefore = count($sim->tasks);
        $this->actingAs($this->tech)->postJson("/api/configuration/{$id}/rollback")
            ->assertStatus(422)->assertJsonPath('error_code', 'NOTHING_APPLIED');
        $this->assertSame($tasksBefore, count($sim->tasks), 'Rollback tidak boleh mengirim task apa pun');
        $this->assertSame('fh_34cf80', $sim->doc['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['1']['SSID']['_value'][0]);
    }

    public function test_zte_adds_wan_inside_existing_connection_device(): void
    {
        config(['ontconfig.provisioning_mode' => 'production']);
        $model = OntModel::where('product_class', 'F609')->first();
        $model->update(['status' => ModelSupportStatus::Supported]);
        $model->parameterMappings()->update(['verified' => true]);

        $doc = $this->fiberhomeDoc(['_id' => 'F42D06-F609-ZTEGC88A0105', '_deviceId' => [
            '_Manufacturer' => 'ZTE', '_OUI' => 'F42D06', '_ProductClass' => 'F609', '_SerialNumber' => 'ZTEGC88A0105',
        ]]);
        $device = OntDevice::create(['genieacs_id' => 'F42D06-F609-ZTEGC88A0105', 'serial_number' => 'ZTEGC88A0105']);
        $sim = (new StatefulGenieAcs($doc))->fake();

        $input = $this->input(['device_id' => $device->id]);
        $input['binding'] = ['lan' => [1], 'wlan' => []];
        $input['ssids'] = [];
        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $input)->assertStatus(202)->json('id');
        $status = $this->actingAs($this->tech)->getJson("/api/configuration/{$id}/status")->json();
        $this->assertSame('success', $status['status'], json_encode($status['timeline']));

        // Tidak ada AddObject pada WANConnectionDevice (ditolak ZTE dengan cwmp.9002)
        $adds = array_column(array_filter($sim->tasks, fn ($t) => $t['name'] === 'addObject'), 'objectName');
        $this->assertSame(['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection'], $adds);

        $wcd = $sim->doc['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'];
        $this->assertSame([1], array_values(array_filter(array_keys($wcd), 'is_int')));
        $new = $wcd['1']['WANIPConnection']['2'];
        $this->assertSame('IP_Bridged', $new['ConnectionType']['_value'][0]);
        $this->assertSame(300, $new['X_ZTE-COM_VLANID']['_value'][0]);
        $this->assertSame(3, $new['X_ZTE-COM_8021P']['_value'][0]);
        $this->assertSame('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1', $new['X_ZTE-COM_LanInterface']['_value'][0]);
        $this->assertSame('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.2', ConfigurationJob::where('uuid', $id)->first()->wanConfigurations->first()->instance_path);

        // Rollback hanya menghapus WANIPConnection baru, WCD.1 (berisi WAN TR069) tetap ada
        $this->actingAs($this->tech)->postJson("/api/configuration/{$id}/rollback")->assertOk()->assertJsonPath('status', 'complete');
        $wcd = $sim->doc['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'];
        $this->assertArrayHasKey('1', $wcd);
        $this->assertArrayHasKey('1', $wcd['1']['WANIPConnection']);
        $this->assertArrayNotHasKey('2', $wcd['1']['WANIPConnection']);
    }

    public function test_technician_cannot_see_other_technicians_jobs(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $id = $this->actingAs($this->tech)->postJson('/api/ont/configure', $this->input())->json('id');

        $this->actingAs(User::factory()->teknisi()->create())->getJson("/api/configuration/{$id}/status")->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get('/history')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->getJson("/api/configuration/{$id}/history")->assertOk()
            ->assertJsonPath('device.serial_number', 'FHTT9D34CF80');
    }

    public function test_config_context_for_wizard(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();

        $this->actingAs($this->tech)->getJson("/api/ont/{$this->device->id}/config-context")->assertOk()
            ->assertJsonPath('lan_count', 4)
            ->assertJsonCount(8, 'ssids')
            ->assertJsonPath('wans.0.vlan_id', 100)
            ->assertJsonPath('capabilities.pppoe_slot', 'ppp')
            ->assertJsonPath('capabilities.binding_method', 'string_list');
    }
}

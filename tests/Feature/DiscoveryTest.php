<?php

namespace Tests\Feature;

use App\Enums\ModelSupportStatus;
use App\Models\OntDevice;
use App\Models\OntModel;
use App\Models\User;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesGenieAcs;
use Tests\Support\StatefulGenieAcs;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    private OntDevice $device;

    private OntModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OntModelSeeder::class);
        $this->useFakeAcsSetting();
        config(['ontconfig.task_poll_interval_ms' => 0, 'ontconfig.task_timeout_seconds' => 1, 'ontconfig.provisioning_mode' => 'production']);
        $this->model = OntModel::where('product_class', 'F609')->first();
        $this->model->update(['status' => ModelSupportStatus::Supported]);
        $this->device = OntDevice::create(['genieacs_id' => 'F42D06-F609-ZTEGC88A0105', 'serial_number' => 'ZTEGC88A0105']);
    }

    private function sim(): StatefulGenieAcs
    {
        $doc = $this->fiberhomeDoc(['_id' => 'F42D06-F609-ZTEGC88A0105', '_deviceId' => ['_Manufacturer' => 'ZTE', '_OUI' => 'F42D06', '_ProductClass' => 'F609', '_SerialNumber' => 'ZTEGC88A0105']]);
        $p = fn ($v, $t) => ['_value' => [$v, $t], '_type' => $t, '_writable' => true];
        $sim = new StatefulGenieAcs($doc);
        // Objek WANPPPConnection kosong di ZTE asli langsung berisi parameter bawaan
        $sim->newObjectParams['WANPPPConnection'] = [
            'Name' => $p('', 'xsd:string'), 'Enable' => $p(false, 'xsd:boolean'), 'ConnectionType' => $p('IP_Routed', 'xsd:string'),
            'NATEnabled' => $p(true, 'xsd:boolean'), 'Username' => $p('', 'xsd:string'), 'Password' => $p('', 'xsd:string'),
            'ConnectionStatus' => $p('Disconnected', 'xsd:string'), 'ExternalIPAddress' => $p('', 'xsd:string'),
            'X_ZTE-COM_ServiceList' => $p('INTERNET', 'xsd:string'), 'X_ZTE-COM_VLANEnable' => $p(0, 'xsd:unsignedInt'),
            'X_ZTE-COM_VLANID' => $p(0, 'xsd:unsignedInt'), 'X_ZTE-COM_8021P' => $p(0, 'xsd:unsignedInt'), 'X_ZTE-COM_LanInterface' => $p('', 'xsd:string'),
        ];

        return $sim->fake();
    }

    public function test_discovery_verifies_ppp_parameters_and_removes_temporary_object(): void
    {
        $sim = $this->sim();
        $this->assertFalse($this->model->parameterMappings()->where('key', 'wan.ppp.username')->value('verified'));

        $r = $this->actingAs(User::factory()->teknisi()->create())->postJson("/api/ont/{$this->device->id}/discover", ['slot' => 'ppp'])
            ->assertOk()->assertJsonPath('deleted', true)->json();

        foreach (['wan.ppp.username', 'wan.ppp.password', 'wan.ppp.vlan_id', 'wan.ppp.vlan_enable', 'wan.ppp.binding', 'wan.ppp.service_list'] as $k) {
            $this->assertContains($k, $r['verified']);
            $this->assertTrue($this->model->parameterMappings()->where('key', $k)->value('verified'), $k);
        }
        $this->assertSame('xsd:unsignedInt', $this->model->parameterMappings()->where('key', 'wan.ppp.vlan_enable')->value('value_type'));

        // Hanya AddObject + DeleteObject: TIDAK ada nilai yang ditulis
        $names = array_column($sim->tasks, 'name');
        $this->assertNotContains('setParameterValues', $names);
        $this->assertSame(['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection'], array_column(array_filter($sim->tasks, fn ($t) => $t['name'] === 'addObject'), 'objectName'));
        $this->assertSame(['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1'], array_column(array_filter($sim->tasks, fn ($t) => $t['name'] === 'deleteObject'), 'objectName'));
        $this->assertSame([], array_filter(array_keys($sim->doc['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection'] ?? []), 'is_int'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ont.discover']);
    }

    public function test_discovery_is_blocked_outside_production_and_for_viewers(): void
    {
        $this->sim();
        $this->actingAs(User::factory()->viewer()->create())->postJson("/api/ont/{$this->device->id}/discover", ['slot' => 'ppp'])->assertForbidden();

        config(['ontconfig.provisioning_mode' => 'simulation']);
        $this->actingAs(User::factory()->teknisi()->create())->postJson("/api/ont/{$this->device->id}/discover", ['slot' => 'ppp'])
            ->assertStatus(422)->assertJsonPath('error_code', 'NOT_PRODUCTION');
    }

    public function test_failed_delete_is_reported_honestly(): void
    {
        $sim = $this->sim();
        $sim->faultOn = 'deleteObject';

        $r = $this->actingAs(User::factory()->teknisi()->create())->postJson("/api/ont/{$this->device->id}/discover", ['slot' => 'ppp'])
            ->assertOk()->assertJsonPath('deleted', false)->json();
        $this->assertStringContainsString('gagal dihapus', $r['message']);
    }
}

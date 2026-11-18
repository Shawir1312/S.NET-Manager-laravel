<?php

namespace Tests\Feature;

use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use App\Models\OntDevice;
use App\Models\OntModel;
use App\Models\User;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\ONTDetector;
use App\Services\ONT\ONTManager;
use App\Services\Provisioning\ConfigurationRequest;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesGenieAcs;
use Tests\TestCase;

class AutoVerifyTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OntModelSeeder::class);
        $this->useFakeAcsSetting();
    }

    private function mapping(string $pc, string $key)
    {
        return OntModel::where('product_class', $pc)->first()->parameterMappings()->where('key', $key)->first();
    }

    public function test_template_matcher_finds_existing_instance(): void
    {
        $tree = ParameterTree::fromDocument($this->fiberhomeDoc());
        $this->assertSame('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.X_FH_LanInterface',
            $tree->matchTemplate('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}.WANIPConnection.{i}.X_FH_LanInterface'));
        $this->assertNull($tree->matchTemplate('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}.WANPPPConnection.{i}.Username'));
        $this->assertSame('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', $tree->matchTemplate('InternetGatewayDevice.LANDevice.1.WLANConfiguration.{ssid}.SSID'));
    }

    public function test_search_detail_auto_verifies_paths_found_on_device(): void
    {
        $this->assertFalse($this->mapping('HG6145D2', 'wan.ip.binding')->verified);
        $this->fakeAcs([$this->fiberhomeDoc()]);
        $device = OntDevice::create(['genieacs_id' => '000AC2-HG6145D2-FHTT9D34CF80', 'serial_number' => 'FHTT9D34CF80']);

        $res = $this->actingAs(User::factory()->teknisi()->create())->getJson("/api/ont/{$device->id}")->assertOk();
        $this->assertContains('wan.ip.binding', $res->json('detection.auto_verified'));

        $m = $this->mapping('HG6145D2', 'wan.ip.binding');
        $this->assertTrue($m->verified);
        $this->assertSame('path', $m->verification_level);
        $this->assertSame('FHTT9D34CF80', $m->verified_device);
        $this->assertStringContainsString('Otomatis: path ditemukan', $m->source);

        // Objek PPP belum ada di ONT -> tidak boleh ikut terverifikasi.
        $this->assertFalse($this->mapping('HG6145D2', 'wan.ppp.username')->verified);
        $this->assertDatabaseHas('audit_logs', ['action' => 'model.auto_verified']);

        // Pencarian berikutnya tidak memverifikasi ulang (tidak ada yang baru).
        $this->actingAs(User::factory()->teknisi()->create())->getJson("/api/ont/{$device->id}")->assertJsonPath('detection.auto_verified', []);
    }

    public function test_real_parameter_types_are_synced_and_values_cast(): void
    {
        // ZTE F609 asli: X_ZTE-COM_VLANEnable bertipe xsd:unsignedInt (nilai 1), bukan boolean.
        $wan = ['X_ZTE-COM_VLANEnable' => ['_value' => [1, 'xsd:unsignedInt'], '_type' => 'xsd:unsignedInt', '_writable' => true]];
        $doc = $this->fiberhomeDoc(['_id' => 'F42D06-F609-ZTEGC88A0105', '_deviceId' => ['_Manufacturer' => 'ZTE', '_OUI' => 'F42D06', '_ProductClass' => 'F609', '_SerialNumber' => 'ZTEGC88A0105'],
            'InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => ['1' => ['WANIPConnection' => ['1' => $wan]]]]]]]);
        $this->fakeAcs([$doc]);
        $device = OntDevice::create(['genieacs_id' => 'F42D06-F609-ZTEGC88A0105', 'serial_number' => 'ZTEGC88A0105']);

        $this->actingAs(User::factory()->teknisi()->create())->getJson("/api/ont/{$device->id}")->assertOk();
        $this->assertSame('xsd:unsignedInt', $this->mapping('F609', 'wan.ip.vlan_enable')->value_type);

        $tree = ParameterTree::fromDocument($doc);
        $ctx = DeviceContext::build($device, $tree, (new ONTDetector)->detect($tree));
        $plan = app(ONTManager::class)->driverFor($ctx->detection)->buildPlan($ctx, ConfigurationRequest::fromArray([
            'service_type' => 'bridge', 'wan' => ['mode' => 'new', 'slot' => 'ip', 'vlan_id' => 300, 'service_list' => 'INTERNET'], 'ssids' => [], 'binding' => ['lan' => [], 'wlan' => []],
        ]));
        $v = collect($plan->allValues())->firstWhere('key', 'wan.ip.vlan_enable');
        $this->assertSame(1, $v['value'], 'true harus dikirim sebagai 1 untuk xsd:unsignedInt');
        $this->assertSame('xsd:unsignedInt', $v['type']);
    }

    public function test_stronger_levels_are_never_downgraded(): void
    {
        $m = $this->mapping('HG6145D2', 'wan.ip.vlan_id');
        $m->update(['verification_level' => 'write', 'source' => 'job produksi']);
        $this->fakeAcs([$this->fiberhomeDoc()]);
        $device = OntDevice::create(['genieacs_id' => '000AC2-HG6145D2-FHTT9D34CF80', 'serial_number' => 'FHTT9D34CF80']);

        $this->actingAs(User::factory()->teknisi()->create())->getJson("/api/ont/{$device->id}")->assertOk();
        $this->assertSame('write', $m->fresh()->verification_level);
        $this->assertSame('job produksi', $m->fresh()->source);
    }

    public function test_unknown_model_of_supported_vendor_is_registered_and_verified(): void
    {
        $doc = $this->fiberhomeDoc(['_id' => 'F42D06-F660-ZTEGAAAA1111', '_deviceId' => [
            '_Manufacturer' => 'ZTE', '_OUI' => 'F42D06', '_ProductClass' => 'F660', '_SerialNumber' => 'ZTEGAAAA1111',
        ]]);
        $this->fakeAcs([$doc]);
        $device = OntDevice::create(['genieacs_id' => 'F42D06-F660-ZTEGAAAA1111', 'serial_number' => 'ZTEGAAAA1111']);

        $res = $this->actingAs(User::factory()->teknisi()->create())->getJson("/api/ont/{$device->id}")->assertOk();
        $res->assertJsonPath('detection.auto_registered', true)->assertJsonPath('detection.supported', true);

        $model = OntModel::where(['vendor' => Vendor::ZTE, 'product_class' => 'F660'])->firstOrFail();
        $this->assertSame(ModelSupportStatus::Testing, $model->status, 'Model baru tetap uji coba sampai admin menyetujui');
        $this->assertSame(8, $model->capability->ssid_count);
        $this->assertTrue($model->parameterMappings()->where('key', 'ssid.name')->value('verified'));
        // Path khusus ZTE tidak ada di dokumen ini -> tetap belum terverifikasi
        $this->assertFalse($model->parameterMappings()->where('key', 'wan.ip.service_list')->value('verified'));
        $this->assertSame($model->id, $device->fresh()->ont_model_id);
    }
}

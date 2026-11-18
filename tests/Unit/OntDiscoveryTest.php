<?php

namespace Tests\Unit;

use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use App\Models\OntModel;
use App\Services\Audit\Redactor;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\DeviceInfoBuilder;
use App\Services\ONT\ONTDetector;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesGenieAcs;
use Tests\TestCase;

class OntDiscoveryTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    private ParameterTree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OntModelSeeder::class);
        $this->tree = ParameterTree::fromDocument($this->fiberhomeDoc());
    }

    public function test_parameter_tree_reads_real_fiberhome_document(): void
    {
        $this->assertSame('000AC2-HG6145D2-FHTT9D34CF80', $this->tree->id());
        $this->assertSame('FiberHome', $this->tree->deviceId()['manufacturer']);
        $this->assertSame('InternetGatewayDevice', $this->tree->root());
        $this->assertSame('RP4313', $this->tree->value('InternetGatewayDevice.DeviceInfo.SoftwareVersion'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $this->tree->instances('InternetGatewayDevice.LANDevice.1.WLANConfiguration'));
        $this->assertSame([1, 2, 3, 4], $this->tree->instances('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig'));
        $this->assertNull($this->tree->value('InternetGatewayDevice.Tidak.Ada'));
        $this->assertGreaterThan(1000, $this->tree->parameterCount());
    }

    public function test_flatten_masks_secrets(): void
    {
        $flat = collect($this->tree->flatten());
        $psk = $flat->firstWhere('path', 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase');
        $this->assertSame(Redactor::MASK, $psk['value']);
        $this->assertTrue($psk['secret']);

        $ssid = $flat->firstWhere('path', 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID');
        $this->assertSame('fh_34cf80', $ssid['value']);
        $this->assertFalse($ssid['secret']);

        $this->assertStringNotContainsString('FIXTURE-SECRET', json_encode($flat));
    }

    public function test_detector_identifies_fiberhome_model_as_simulation_only(): void
    {
        $r = (new ONTDetector)->detect($this->tree);

        $this->assertSame(Vendor::FiberHome, $r->vendor);
        $this->assertSame('HG6145D2', $r->model->model_name);
        $this->assertTrue($r->isSupported());
        $this->assertTrue($r->isSimulationOnly());
    }

    public function test_detector_prefers_specific_firmware_and_rejects_unknown(): void
    {
        $specific = OntModel::create(['vendor' => Vendor::FiberHome, 'model_name' => 'HG6145D2 RP43xx', 'product_class' => 'HG6145D2',
            'firmware_pattern' => '^RP43', 'status' => ModelSupportStatus::Supported]);

        $r = (new ONTDetector)->detect($this->tree);
        $this->assertSame($specific->id, $r->model->id);
        $this->assertFalse($r->isSimulationOnly());

        $unknown = ParameterTree::fromDocument($this->fiberhomeDoc(['_deviceId' => ['_ProductClass' => 'HG9999']]));
        $this->assertFalse((new ONTDetector)->detect($unknown)->isSupported());

        $otherVendor = ParameterTree::fromDocument($this->fiberhomeDoc(['_deviceId' => ['_Manufacturer' => 'ZIONCOM']]));
        $r = (new ONTDetector)->detect($otherVendor);
        $this->assertNull($r->vendor);
        $this->assertStringContainsString('belum didukung', $r->message());
    }

    public function test_device_info_counts_and_capabilities(): void
    {
        $detection = (new ONTDetector)->detect($this->tree);
        $info = (new DeviceInfoBuilder)->build($this->tree, $detection);

        $this->assertSame(['wan' => 1, 'wan_ip' => 1, 'wan_ppp' => 0, 'lan' => 4, 'ssid' => 8], $info['counts']);
        $this->assertSame('192.168.99.251', $info['ip_address']);
        $this->assertSame('IP_Routed', $info['wan_connections'][0]['connection_type']);
        $this->assertSame('1_TR069_VOIP_R_VID_100', $info['wan_connections'][0]['name']);
        $this->assertSame('2.4GHz', $info['ssids'][0]['band']);
        $this->assertSame('5GHz', $info['ssids'][4]['band']);
        $this->assertTrue($info['ssids'][0]['has_password']);
        $this->assertTrue($info['capabilities']['has_5g']);
        $this->assertFalse($info['capabilities']['supports_pppoe']);
        $this->assertSame('string_list', $info['capabilities']['binding_method']);
        $this->assertTrue($info['completeness']['complete']);
        $this->assertStringNotContainsString('FIXTURE-SECRET', json_encode($info));
    }
}

<?php

namespace Tests\Unit;

use App\Enums\ServiceType;
use App\Enums\Vendor;
use App\Models\ConfigurationTemplate;
use App\Models\OntModel;
use App\Models\OntParameterMapping;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_detection_from_manufacturer(): void
    {
        $this->assertSame(Vendor::FiberHome, Vendor::fromManufacturer('FiberHome'));
        $this->assertSame(Vendor::ZTE, Vendor::fromManufacturer('ZTE'));
        $this->assertSame(Vendor::Huawei, Vendor::fromManufacturer('Huawei Technologies Co., Ltd'));
        $this->assertNull(Vendor::fromManufacturer('ZIONCOM'));
        $this->assertNull(Vendor::fromManufacturer(null));
    }

    public function test_template_never_stores_passwords(): void
    {
        $t = ConfigurationTemplate::create([
            'name' => 'PPPoE HOME', 'service_type' => ServiceType::Pppoe,
            'payload' => ['vlan_id' => 200, 'pppoe_password' => 'x', 'ssid' => [['index' => 1, 'password' => 'y']]],
        ]);

        $this->assertEquals(['vlan_id' => 200, 'ssid' => [['index' => 1]]], $t->fresh()->payload); // MySQL JSON tidak menjaga urutan key
    }

    public function test_mapping_path_placeholders(): void
    {
        $m = new OntParameterMapping(['key' => 'wan.ip.vlan_id', 'path_template' => 'IGD.WANDevice.1.WANConnectionDevice.{wcd}.WANIPConnection.{i}.VLANID']);

        $this->assertSame('IGD.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.VLANID', $m->resolvePath(['wcd' => 2, 'i' => 1]));

        $this->expectException(\InvalidArgumentException::class);
        $m->resolvePath(['wcd' => 2]);
    }

    public function test_seeder_only_marks_real_device_paths_as_verified(): void
    {
        $this->seed(OntModelSeeder::class);

        $fh = OntModel::where('product_class', 'HG6145D2')->firstOrFail();
        $this->assertSame(8, $fh->capability->ssid_count);
        $this->assertSame(4, $fh->capability->lan_ports);
        $this->assertTrue($fh->parameterMappings()->where('key', 'wan.ip.vlan_id')->value('verified'));
        // Format binding FiberHome belum diketahui -> tidak boleh verified.
        $this->assertFalse($fh->parameterMappings()->where('key', 'wan.ip.binding')->value('verified'));

        // Semua mapping ZTE/Huawei berasal dari provision lama -> belum verified.
        $this->assertSame(0, OntParameterMapping::whereHas('ontModel', fn ($q) => $q->whereIn('vendor', ['zte', 'huawei']))->where('verified', true)->count());
    }

    public function test_firmware_pattern_matching(): void
    {
        $any = new OntModel(['firmware_pattern' => '*']);
        $rp = new OntModel(['firmware_pattern' => '^RP43']);

        $this->assertTrue($any->matchesFirmware(null));
        $this->assertTrue($rp->matchesFirmware('RP4313'));
        $this->assertFalse($rp->matchesFirmware('RP2600'));
    }
}

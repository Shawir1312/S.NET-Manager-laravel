<?php

namespace Tests\Unit;

use App\Enums\ServiceType;
use App\Services\ONT\Mappers\BindingMapper;
use App\Services\ONT\Mappers\SSIDMapper;
use App\Services\ONT\Mappers\VLANMapper;
use App\Services\ONT\Mappers\WANMapper;
use PHPUnit\Framework\TestCase;

class MappersTest extends TestCase
{
    public function test_wan_connection_type_rules(): void
    {
        $this->assertSame('PPPoE_Bridged', WANMapper::connectionType(ServiceType::Bridge, 'ppp'));
        $this->assertSame('IP_Bridged', WANMapper::connectionType(ServiceType::Bridge, 'ip'));
        $this->assertSame('IP_Routed', WANMapper::connectionType(ServiceType::Pppoe, 'ppp'));
        $this->assertSame('INTERNET_B_VID_100', WANMapper::defaultName(ServiceType::Bridge, 100, 'INTERNET'));
    }

    public function test_binding_string_from_checkboxes(): void
    {
        $paths = BindingMapper::interfacePaths('InternetGatewayDevice', [2, 1], [5, 1]);
        $this->assertSame(
            'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.2,'
            .'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.5',
            BindingMapper::stringList($paths),
        );
    }

    public function test_vlan_validation(): void
    {
        $this->assertSame([], VLANMapper::validate(100, 0));
        $this->assertNotEmpty(VLANMapper::validate(0, null));
        $this->assertNotEmpty(VLANMapper::validate(4095, null));
        $this->assertNotEmpty(VLANMapper::validate(100, 8));
        $this->assertNotEmpty(VLANMapper::validate(50, null, 100, 200));
    }

    public function test_ssid_validation(): void
    {
        $base = ['index' => 1, 'ssid_name' => 'RUMAH', 'password' => null, 'security' => null, 'enabled' => null, 'hidden' => null];
        $this->assertSame([], SSIDMapper::validate($base));
        $this->assertNotEmpty(SSIDMapper::validate(['security' => 'None', 'password' => 'abcdefgh'] + $base));
        $this->assertNotEmpty(SSIDMapper::validate(['security' => '11i', 'password' => 'short'] + $base));
        $this->assertSame([], SSIDMapper::validate(['security' => 'WPAand11i', 'password' => 'panjang123'] + $base));
        $this->assertNotEmpty(SSIDMapper::validate(['ssid_name' => str_repeat('x', 33)] + $base));
    }
}

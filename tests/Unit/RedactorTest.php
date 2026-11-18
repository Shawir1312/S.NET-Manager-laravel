<?php

namespace Tests\Unit;

use App\Services\Audit\Redactor;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    public function test_redacts_nested_keys_and_tr069_paths(): void
    {
        $out = Redactor::redact([
            'pppoe_username' => 'pelanggan',
            'pppoe_password' => 'rahasia',
            'wifi' => ['ssid' => 'RUMAH', 'wifi_password' => 'wifirahasia'],
            'parameterValues' => [
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password', 'pppx', 'xsd:string'],
                ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase', 'wifix', 'xsd:string'],
                ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'RUMAH', 'xsd:string'],
            ],
        ]);

        $this->assertSame('pelanggan', $out['pppoe_username']);
        $this->assertSame(Redactor::MASK, $out['pppoe_password']);
        $this->assertSame(Redactor::MASK, $out['wifi']['wifi_password']);
        $this->assertSame(Redactor::MASK, $out['parameterValues'][0][1]);
        $this->assertSame(Redactor::MASK, $out['parameterValues'][1][1]);
        $this->assertSame('RUMAH', $out['parameterValues'][2][1]);
        $this->assertStringNotContainsString('rahasia', json_encode($out));

        // Kunci array bernama "key" (mis. di audit mapping) bukan rahasia; path TR-069 ".Key" tetap rahasia.
        $this->assertSame('wan.ip.binding', Redactor::redact(['key' => 'wan.ip.binding'])['key']);
        $this->assertTrue(Redactor::isSecretKey('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_FH_Radius.Key'));
    }
}

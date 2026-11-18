<?php

namespace App\Services\ONT\Drivers\Huawei;

/**
 * Mapping Huawei. "unverified": dari provision GenieACS lama, belum dibaca dari ONT asli.
 * Binding Huawei berupa FLAG per port: X_HW_LANBIND.Lan{n}Enable / SSID{n}Enable.
 */
final class HuaweiParameters
{
    public const SOURCE = 'Provision "default" GenieACS lama (belum dicek di ONT asli)';

    /** @return array<string, array{0: string, 1: string}> */
    public static function unverified(): array
    {
        $wan = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}';
        $map = [
            'optical.rx_power' => ['InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower', 'xsd:string'],
        ];
        foreach (['ip' => 'WANIPConnection', 'ppp' => 'WANPPPConnection'] as $slot => $object) {
            $p = "{$wan}.{$object}.{i}";
            $map["wan.{$slot}.service_list"] = [$p.'.X_HW_SERVICELIST', 'xsd:string'];
            $map["wan.{$slot}.vlan_id"] = [$p.'.X_HW_VLAN', 'xsd:unsignedInt'];
            foreach ([1, 2, 3, 4] as $n) {
                $map["wan.{$slot}.bind_lan{$n}"] = [$p.".X_HW_LANBIND.Lan{$n}Enable", 'xsd:boolean'];
                $map["wan.{$slot}.bind_ssid{$n}"] = [$p.".X_HW_LANBIND.SSID{$n}Enable", 'xsd:boolean'];
            }
        }

        return $map;
    }

    public static function serviceListValues(): array
    {
        return ['INTERNET' => 'INTERNET', 'Other' => 'OTHER'];
    }
}

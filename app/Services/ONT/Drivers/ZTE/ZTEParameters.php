<?php

namespace App\Services\ONT\Drivers\ZTE;

/**
 * Mapping ZTE. Semua masih "unverified": diambil dari provision GenieACS lama,
 * BELUM dibaca dari ONT ZTE asli. Path binding F670L belum diketahui dan
 * harus diisi lewat menu Device Models setelah dicek di ONT asli.
 */
final class ZTEParameters
{
    public const SOURCE = 'Provision "default" GenieACS lama (belum dicek di ONT asli)';

    /** @return array<string, array{0: string, 1: string}> */
    public static function unverified(): array
    {
        $wan = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}';

        $map = [
            'optical.rx_power' => ['InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower', 'xsd:string'],
        ];
        // Nama parameter dikonfirmasi ada di ZTE F609 asli (ZTEGC88A0105, 2026-11-18):
        // X_ZTE-COM_ServiceList ("INTERNET_TR069"), X_ZTE-COM_VLANEnable, X_ZTE-COM_VLANID, X_ZTE-COM_8021P, X_ZTE-COM_LanInterface.
        foreach (['ip' => 'WANIPConnection', 'ppp' => 'WANPPPConnection'] as $slot => $object) {
            $p = "{$wan}.{$object}.{i}";
            $map["wan.{$slot}.service_list"] = [$p.'.X_ZTE-COM_ServiceList', 'xsd:string'];
            $map["wan.{$slot}.vlan_enable"] = [$p.'.X_ZTE-COM_VLANEnable', 'xsd:boolean'];
            $map["wan.{$slot}.vlan_id"] = [$p.'.X_ZTE-COM_VLANID', 'xsd:unsignedInt'];
            $map["wan.{$slot}.vlan_priority"] = [$p.'.X_ZTE-COM_8021P', 'xsd:unsignedInt'];
            $map["wan.{$slot}.binding"] = [$p.'.X_ZTE-COM_LanInterface', 'xsd:string'];
        }

        return $map;
    }

    public static function serviceListValues(): array
    {
        return ['INTERNET' => 'INTERNET', 'Other' => 'OTHER'];
    }
}

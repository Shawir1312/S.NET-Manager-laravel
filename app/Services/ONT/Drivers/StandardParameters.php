<?php

namespace App\Services\ONT\Drivers;

/**
 * Path STANDAR TR-098 (Broadband Forum). Dipakai sebagai dasar untuk semua model,
 * selalu "unverified" sampai dibaca dari ONT asli. Placeholder:
 *   {wcd} = WANConnectionDevice, {i} = instance WAN, {ssid} = WLANConfiguration.
 */
final class StandardParameters
{
    public const SOURCE = 'Standar TR-098 (belum dibaca dari ONT asli)';

    /** @return array<string, array{0: string, 1: string}> */
    public static function all(): array
    {
        $wan = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}';
        $lan = 'InternetGatewayDevice.LANDevice.1';
        $map = [
            'wan.connection_device_count' => ['InternetGatewayDevice.WANDevice.1.WANConnectionNumberOfEntries', 'xsd:unsignedInt'],
            'wan.ip_count' => [$wan.'.WANIPConnectionNumberOfEntries', 'xsd:unsignedInt'],
            'wan.ppp_count' => [$wan.'.WANPPPConnectionNumberOfEntries', 'xsd:unsignedInt'],
            'lan.port_count' => [$lan.'.LANEthernetInterfaceNumberOfEntries', 'xsd:unsignedInt'],
            'ssid.count' => [$lan.'.LANWLANConfigurationNumberOfEntries', 'xsd:unsignedInt'],
            'ssid.name' => [$lan.'.WLANConfiguration.{ssid}.SSID', 'xsd:string'],
            'ssid.enable' => [$lan.'.WLANConfiguration.{ssid}.Enable', 'xsd:boolean'],
            'ssid.beacon_type' => [$lan.'.WLANConfiguration.{ssid}.BeaconType', 'xsd:string'],
            'ssid.advertise' => [$lan.'.WLANConfiguration.{ssid}.SSIDAdvertisementEnabled', 'xsd:boolean'],
            'ssid.passphrase' => [$lan.'.WLANConfiguration.{ssid}.PreSharedKey.1.KeyPassphrase', 'xsd:string'],
            'ssid.auth_mode' => [$lan.'.WLANConfiguration.{ssid}.IEEE11iAuthenticationMode', 'xsd:string'],
            'ssid.encryption' => [$lan.'.WLANConfiguration.{ssid}.IEEE11iEncryptionModes', 'xsd:string'],
            'ssid.wpa_auth_mode' => [$lan.'.WLANConfiguration.{ssid}.WPAAuthenticationMode', 'xsd:string'],
            'ssid.wpa_encryption' => [$lan.'.WLANConfiguration.{ssid}.WPAEncryptionModes', 'xsd:string'],
            'ssid.channel' => [$lan.'.WLANConfiguration.{ssid}.Channel', 'xsd:unsignedInt'],
            'ssid.auto_channel' => [$lan.'.WLANConfiguration.{ssid}.AutoChannelEnable', 'xsd:boolean'],
        ];

        foreach (['ip' => 'WANIPConnection', 'ppp' => 'WANPPPConnection'] as $slot => $object) {
            $p = "{$wan}.{$object}.{i}";
            $map["wan.{$slot}.name"] = [$p.'.Name', 'xsd:string'];
            $map["wan.{$slot}.enable"] = [$p.'.Enable', 'xsd:boolean'];
            $map["wan.{$slot}.connection_type"] = [$p.'.ConnectionType', 'xsd:string'];
            $map["wan.{$slot}.nat_enabled"] = [$p.'.NATEnabled', 'xsd:boolean'];
            $map["wan.{$slot}.status"] = [$p.'.ConnectionStatus', 'xsd:string'];
            $map["wan.{$slot}.external_ip"] = [$p.'.ExternalIPAddress', 'xsd:string'];
        }
        $map['wan.ppp.username'] = [$wan.'.WANPPPConnection.{i}.Username', 'xsd:string'];
        $map['wan.ppp.password'] = [$wan.'.WANPPPConnection.{i}.Password', 'xsd:string'];

        return $map;
    }
}

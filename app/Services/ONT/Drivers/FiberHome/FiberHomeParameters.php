<?php

namespace App\Services\ONT\Drivers\FiberHome;

/**
 * Mapping FiberHome. "verified" = path DIBACA dari ONT asli HG6145D2 (FW RP4313,
 * dump GenieACS 2026-11-17). "unverified" = dugaan, wajib dicek di ONT asli.
 */
final class FiberHomeParameters
{
    public const VERIFIED_SOURCE = 'Dump GenieACS 2026-11-17, FHTT9D34CF80, FW RP4313';

    /** @return array<string, array{0: string, 1: string}> */
    public static function verified(): array
    {
        $wan = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}';
        $ip = $wan.'.WANIPConnection.{i}';
        $wlan = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.{ssid}';

        return [
            'wan.connection_device_count' => ['InternetGatewayDevice.WANDevice.1.WANConnectionNumberOfEntries', 'xsd:unsignedInt'],
            'wan.ip_count' => [$wan.'.WANIPConnectionNumberOfEntries', 'xsd:unsignedInt'],
            'wan.ppp_count' => [$wan.'.WANPPPConnectionNumberOfEntries', 'xsd:unsignedInt'],
            'wan.ip.name' => [$ip.'.Name', 'xsd:string'],
            'wan.ip.enable' => [$ip.'.Enable', 'xsd:boolean'],
            'wan.ip.connection_type' => [$ip.'.ConnectionType', 'xsd:string'],
            'wan.ip.nat_enabled' => [$ip.'.NATEnabled', 'xsd:boolean'],
            'wan.ip.vlan_enable' => [$ip.'.VLANEnable', 'xsd:boolean'],
            'wan.ip.vlan_id' => [$ip.'.VLANID', 'xsd:unsignedInt'],
            'wan.ip.service_list' => [$ip.'.X_FH_ServiceList', 'xsd:string'],
            'wan.ip.external_ip' => [$ip.'.ExternalIPAddress', 'xsd:string'],
            'wan.ip.status' => [$ip.'.ConnectionStatus', 'xsd:string'],
            'wan.link.vlan_id' => [$wan.'.X_FH_WANGponLinkConfig.VLANID', 'xsd:unsignedInt'],
            'wan.link.vlan_priority' => [$wan.'.X_FH_WANGponLinkConfig.802-1pMark', 'xsd:unsignedInt'],
            'lan.port_count' => ['InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceNumberOfEntries', 'xsd:unsignedInt'],
            'ssid.count' => ['InternetGatewayDevice.LANDevice.1.LANWLANConfigurationNumberOfEntries', 'xsd:unsignedInt'],
            'ssid.name' => [$wlan.'.SSID', 'xsd:string'],
            'ssid.enable' => [$wlan.'.Enable', 'xsd:boolean'],
            'ssid.beacon_type' => [$wlan.'.BeaconType', 'xsd:string'],
            'ssid.advertise' => [$wlan.'.SSIDAdvertisementEnabled', 'xsd:boolean'],
            'ssid.passphrase' => [$wlan.'.PreSharedKey.1.KeyPassphrase', 'xsd:string'],
            'ssid.auth_mode' => [$wlan.'.IEEE11iAuthenticationMode', 'xsd:string'],
            'ssid.encryption' => [$wlan.'.IEEE11iEncryptionModes', 'xsd:string'],
            'ssid.wpa_auth_mode' => [$wlan.'.WPAAuthenticationMode', 'xsd:string'],
            'ssid.wpa_encryption' => [$wlan.'.WPAEncryptionModes', 'xsd:string'],
            'ssid.channel' => [$wlan.'.Channel', 'xsd:unsignedInt'],
            'ssid.auto_channel' => [$wlan.'.AutoChannelEnable', 'xsd:boolean'],
            'optical.rx_power' => ['InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower', 'xsd:string'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> path, type, catatan */
    public static function unverified(): array
    {
        $wan = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}';

        return [
            'wan.ip.binding' => [$wan.'.WANIPConnection.{i}.X_FH_LanInterface', 'xsd:string', 'Path ada di ONT, format nilai belum diketahui (kosong).'],
            'wan.ppp.vlan_enable' => [$wan.'.WANPPPConnection.{i}.VLANEnable', 'xsd:boolean', 'Dugaan: simetri dengan WANIPConnection.'],
            'wan.ppp.vlan_id' => [$wan.'.WANPPPConnection.{i}.VLANID', 'xsd:unsignedInt', 'Dugaan: simetri dengan WANIPConnection.'],
            'wan.ppp.service_list' => [$wan.'.WANPPPConnection.{i}.X_FH_ServiceList', 'xsd:string', 'Dugaan: simetri dengan WANIPConnection.'],
            'wan.ppp.binding' => [$wan.'.WANPPPConnection.{i}.X_FH_LanInterface', 'xsd:string', 'Dugaan: simetri dengan WANIPConnection.'],
        ];
    }

    /** Nilai service list. Contoh nilai asli di ONT: "TR069,VOIP". */
    public static function serviceListValues(): array
    {
        return ['INTERNET' => 'INTERNET', 'Other' => 'OTHER'];
    }
}

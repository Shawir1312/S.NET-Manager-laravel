<?php

namespace App\Services\ONT\Mappers;

use App\Enums\ServiceType;

/**
 * Aturan WAN (spesifikasi ISP):
 *  - BRIDGE di slot WAN PPP -> PPPoE_Bridged ; di slot WAN IP -> IP_Bridged
 *  - PPPoE -> IP_Routed (kredensial PPPoE di TR-098 hanya ada di WANPPPConnection)
 *  - Service list: INTERNET atau Other (Bridge), INTERNET (PPPoE)
 */
final class WANMapper
{
    public const SERVICE_LISTS = ['INTERNET', 'Other'];

    public static function connectionType(ServiceType $service, string $slot): string
    {
        return match ($service) {
            ServiceType::Bridge => $slot === 'ppp' ? 'PPPoE_Bridged' : 'IP_Bridged',
            ServiceType::Pppoe => 'IP_Routed',
        };
    }

    public static function objectName(string $slot): string
    {
        return $slot === 'ppp' ? 'WANPPPConnection' : 'WANIPConnection';
    }

    /** Nama WAN default bila teknisi tidak mengisi, mis. "INTERNET_B_VID_100". */
    public static function defaultName(ServiceType $service, int $vlan, string $serviceList): string
    {
        return strtoupper($serviceList).'_'.($service === ServiceType::Bridge ? 'B' : 'R').'_VID_'.$vlan;
    }
}

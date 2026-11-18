<?php

namespace App\Services\ONT\Mappers;

/**
 * Membuat nilai binding LAN/WLAN dari pilihan checkbox.
 * Contoh string list:
 *   InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1
 */
final class BindingMapper
{
    public static function lanPath(string $root, int $n): string
    {
        return "{$root}.LANDevice.1.LANEthernetInterfaceConfig.{$n}";
    }

    public static function wlanPath(string $root, int $n): string
    {
        return "{$root}.LANDevice.1.WLANConfiguration.{$n}";
    }

    /**
     * @param  list<int>  $lan
     * @param  list<int>  $wlan
     * @return list<string>
     */
    public static function interfacePaths(string $root, array $lan, array $wlan): array
    {
        sort($lan);
        sort($wlan);

        return [
            ...array_map(fn ($n) => self::lanPath($root, $n), $lan),
            ...array_map(fn ($n) => self::wlanPath($root, $n), $wlan),
        ];
    }

    /** @param list<string> $paths */
    public static function stringList(array $paths): string
    {
        return implode(',', $paths);
    }
}

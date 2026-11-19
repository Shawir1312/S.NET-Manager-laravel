<?php

namespace App\Services\ONT;

use App\Services\GenieACS\GenieACSService;
use App\Services\GenieACS\ParameterTree;

/**
 * Data "live" ONT untuk Monitor ONT: IP WAN, SSID, redaman RX/TX (port dari GenieACS::getInfo/getWifi/getOptical S.NET lama).
 * Dibaca dari dokumen GenieACS hasil proyeksi PROJECTION, disimpan ke ont_devices saat sinkron.
 */
class OntLiveInfo
{
    /** Proyeksi daftar ONT: identitas + versi + node yang berisi IP, SSID & optik di berbagai merek. */
    public const PROJECTION = GenieACSService::IDENTITY_PROJECTION
        .',InternetGatewayDevice.DeviceInfo.HardwareVersion,InternetGatewayDevice.DeviceInfo.SoftwareVersion'
        .',Device.DeviceInfo.HardwareVersion,Device.DeviceInfo.SoftwareVersion'
        .',VirtualParameters'
        .',InternetGatewayDevice.WANDevice.1'
        .',InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID,InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID'
        .',InternetGatewayDevice.X_ZTE-COM_WANPONInterfaceConfig,InternetGatewayDevice.X_CDTC_WANPONInterfaceConfig'
        .',InternetGatewayDevice.X_ALU_OntOpticalParam,InternetGatewayDevice.X_CMS_PrivateNode,InternetGatewayDevice.DeviceInfo.XponInterface'
        .',Device.Optical.Interface.1.RXPower,Device.Optical.Interface.1.TXPower'
        .',Device.WiFi.SSID.1.SSID,Device.WiFi.SSID.2.SSID,Device.PPP.Interface.1.IPCP.LocalIPAddress';

    private const RX_PATHS = [
        'VirtualParameters.RXPower', 'VirtualParameters.Redaman', 'VirtualParameters.OpticalRX', 'VirtualParameters.rxPower', 'VirtualParameters.rx_power',
        'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower', 'InternetGatewayDevice.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
        'InternetGatewayDevice.WANDevice.1.X_HW_OpticalParameter.RxPower', 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower', 'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower',
        'InternetGatewayDevice.WANDevice.1.X_CDTC_WANPONInterfaceConfig.RXPower', 'InternetGatewayDevice.X_CDTC_WANPONInterfaceConfig.RXPower',
        'InternetGatewayDevice.WANDevice.1.WANGponInterfaceConfig.OpticalParameter.RxPower', 'InternetGatewayDevice.WANDevice.1.WANEponInterfaceConfig.OpticalParameter.RxPower',
        'InternetGatewayDevice.X_ALU_OntOpticalParam.RXPower', 'InternetGatewayDevice.DeviceInfo.XponInterface.RXPower', 'Device.Optical.Interface.1.RXPower',
    ];

    private const TX_PATHS = [
        'VirtualParameters.TXPower', 'VirtualParameters.OpticalTX', 'VirtualParameters.txPower',
        'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.TXPower', 'InternetGatewayDevice.WANDevice.1.X_HW_OpticalParameter.TxPower',
        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower', 'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower',
        'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.TXPower', 'InternetGatewayDevice.X_ALU_OntOpticalParam.TXPower', 'Device.Optical.Interface.1.TXPower',
    ];

    /** @return array{ip_address: ?string, ssid_24: ?string, ssid_5g: ?string, rx: ?string, tx: ?string} */
    public static function fromTree(ParameterTree $tree): array
    {
        $ssid24 = self::first($tree, ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'VirtualParameters.WiFiSSID', 'Device.WiFi.SSID.1.SSID']);
        $ssid5 = self::first($tree, ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID', 'Device.WiFi.SSID.2.SSID']);
        [$rxRaw, $txRaw] = [self::first($tree, self::RX_PATHS), self::first($tree, self::TX_PATHS)];

        // Fallback: cari nama parameter optik di seluruh node yang diproyeksikan (merek yang tidak ada di daftar).
        if ($rxRaw === null || $txRaw === null) {
            foreach ($tree->flatten(null, false) as $p) {
                $v = self::clean($p['value']);
                if ($v === null) {
                    continue;
                }
                $rxRaw ??= preg_match('/(rx_?power|opticalrx|redaman|transceiverrx)$/i', $p['path']) ? $v : null;
                $txRaw ??= preg_match('/(tx_?power|opticaltx|transceivertx)$/i', $p['path']) ? $v : null;
            }
        }
        if ($rxRaw === null && is_array($pon = json_decode((string) $tree->stringValue('InternetGatewayDevice.X_CMS_PrivateNode.pon_info'), true))) {
            $rxRaw = self::clean($pon['rxPower'] ?? null);
            $txRaw ??= self::clean($pon['txPower'] ?? null);
        }

        $rx = self::toDbm($rxRaw);
        $tx = self::toDbm($txRaw);

        return [
            'ip_address' => self::wanIp($tree),
            'ssid_24' => $ssid24,
            'ssid_5g' => $ssid5 !== $ssid24 ? $ssid5 : null,
            'rx' => $rx === null ? null : (string) $rx,
            'tx' => $tx === null ? null : (string) $tx,
        ];
    }

    /** IP TR-069/WAN IP, lalu IP PPPoE (sama urutannya dengan S.NET lama). */
    private static function wanIp(ParameterTree $tree): ?string
    {
        $candidates = ['VirtualParameters.IPTR069', 'VirtualParameters.ip_tr069'];
        foreach (['WANIPConnection', 'WANPPPConnection'] as $type) {
            foreach (range(1, 5) as $s) {
                foreach (range(1, 3) as $c) {
                    $candidates[] = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$s}.{$type}.{$c}.ExternalIPAddress";
                }
            }
            if ($type === 'WANIPConnection') {
                array_push($candidates, 'VirtualParameters.IPPPPOE', 'VirtualParameters.pppoeIP', 'VirtualParameters.ip_pppoe');
            }
        }
        $candidates[] = 'Device.PPP.Interface.1.IPCP.LocalIPAddress';

        foreach ($candidates as $path) {
            $v = $tree->stringValue($path);
            if ($v !== null && $v !== '0.0.0.0' && filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
        }

        return null;
    }

    /** @param  list<string>  $paths */
    private static function first(ParameterTree $tree, array $paths): ?string
    {
        foreach ($paths as $path) {
            if (($v = self::clean($tree->value($path))) !== null) {
                return $v;
            }
        }

        return null;
    }

    private static function clean(mixed $v): ?string
    {
        $v = $v === null || is_array($v) ? null : trim((string) $v);

        return in_array($v, [null, '', '0', '0.00', 'N/A', '-'], true) ? null : $v;
    }

    /** Normalisasi berbagai satuan vendor ke dBm (sama dengan convertDbm S.NET lama). */
    public static function toDbm(?string $raw): ?float
    {
        if ($raw === null || ! is_numeric($raw) || (float) $raw == 0.0) {
            return null;
        }
        $v = (float) $raw;
        if ($v < 0) {
            return round(match (true) {
                $v >= -60 => $v,
                $v >= -700 => $v / 10,
                $v >= -6000 => $v / 100,
                default => $v / 1000,
            }, 2);
        }
        if ($v <= 10) {
            return round($v, 2);
        }
        if ($v <= 100) {
            return round(-$v / 10, 2);
        }
        if ($v <= 1000000) {
            $db = 10 * log10($v * 1e-6); // satuan 0.1 µW / µW (Huawei, FiberHome)
            if ($db >= -60 && $db <= 10) {
                return round($db, 2);
            }
        }

        return round($v, 2);
    }
}

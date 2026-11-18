<?php

namespace App\Services\ONT;

use App\Services\Audit\Redactor;
use App\Services\GenieACS\ParameterTree;

/**
 * Menyusun ringkasan ONT dari pohon parameter GenieACS memakai path standar
 * TR-098 saja. Parameter khusus vendor (VLAN, service list, binding) dibaca oleh
 * driver vendor di Phase 3.
 */
class DeviceInfoBuilder
{
    /** @return array<string, mixed> */
    public function build(ParameterTree $tree, DetectionResult $detection): array
    {
        $root = $tree->root();
        $id = $tree->deviceId();
        $wans = $this->wanConnections($tree, $root);
        $ssids = $this->ssids($tree, $root);
        $lanCount = count($tree->instances($root.'.LANDevice.1.LANEthernetInterfaceConfig'));

        $capability = $detection->model?->capability;

        return [
            'genieacs_id' => $tree->id(),
            'serial_number' => $id['serial_number'],
            'manufacturer' => $id['manufacturer'],
            'oui' => $id['oui'],
            'product_class' => $id['product_class'],
            'model_name' => $tree->stringValue($root.'.DeviceInfo.ModelName') ?: $id['product_class'],
            'hardware_version' => $detection->hardwareVersion,
            'software_version' => $detection->softwareVersion,
            'ip_address' => $this->wanIp($wans),
            'tr069_ip' => $this->connectionRequestHost($tree, $root),
            'last_inform' => $tree->lastInform()?->toIso8601String(),
            'registered' => $tree->registered()?->toIso8601String(),
            'uptime_seconds' => $tree->value($root.'.DeviceInfo.UpTime'),
            'data_model' => $root === 'Device' ? 'TR-181' : 'TR-098',
            'counts' => [
                'wan' => count($wans),
                'wan_ip' => count(array_filter($wans, fn ($w) => $w['slot'] === 'ip')),
                'wan_ppp' => count(array_filter($wans, fn ($w) => $w['slot'] === 'ppp')),
                'lan' => $lanCount,
                'ssid' => count($ssids),
            ],
            'wan_connections' => $wans,
            'ssids' => $ssids,
            'capabilities' => [
                // Dari ONT (hasil discovery)
                'lan_ports' => $lanCount ?: $capability?->lan_ports,
                'ssid_count' => count($ssids) ?: $capability?->ssid_count,
                'has_5g' => $this->has5g($ssids) ?? $capability?->has_5g,
                // Dari Device Models (diverifikasi manual)
                'supports_bridge' => (bool) $capability?->supports_bridge,
                'supports_pppoe' => (bool) $capability?->supports_pppoe,
                'binding_method' => $capability?->binding_method?->value ?? 'unknown',
                'vlan_range' => $capability ? [$capability->vlan_min, $capability->vlan_max] : null,
            ],
            'completeness' => $this->completeness($tree, $root, $wans, $ssids),
            'parameter_count' => $tree->parameterCount(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function wanConnections(ParameterTree $tree, string $root): array
    {
        $out = [];
        $base = $root.'.WANDevice.1.WANConnectionDevice';

        foreach ($tree->instances($base) as $wcd) {
            foreach (['ip' => 'WANIPConnection', 'ppp' => 'WANPPPConnection'] as $slot => $object) {
                foreach ($tree->instances("{$base}.{$wcd}.{$object}") as $i) {
                    $path = "{$base}.{$wcd}.{$object}.{$i}";
                    $out[] = [
                        'path' => $path,
                        'slot' => $slot,
                        'wcd' => $wcd,
                        'index' => $i,
                        'name' => $tree->stringValue("{$path}.Name"),
                        'enabled' => $tree->value("{$path}.Enable"),
                        'connection_type' => $tree->stringValue("{$path}.ConnectionType"),
                        'status' => $tree->stringValue("{$path}.ConnectionStatus"),
                        'external_ip' => $tree->stringValue("{$path}.ExternalIPAddress"),
                        'nat_enabled' => $tree->value("{$path}.NATEnabled"),
                        'username' => $slot === 'ppp' ? $tree->stringValue("{$path}.Username") : null,
                    ];
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function ssids(ParameterTree $tree, string $root): array
    {
        $out = [];
        $base = $root.'.LANDevice.1.WLANConfiguration';

        foreach ($tree->instances($base) as $i) {
            $path = "{$base}.{$i}";
            $standard = $tree->stringValue("{$path}.Standard");
            $band = $tree->stringValue("{$path}.OperatingFrequencyBand");
            $out[] = [
                'index' => $i,
                'path' => $path,
                'ssid' => $tree->stringValue("{$path}.SSID"),
                'enabled' => $tree->value("{$path}.Enable"),
                'beacon_type' => $tree->stringValue("{$path}.BeaconType"),
                'hidden' => ($adv = $tree->value("{$path}.SSIDAdvertisementEnabled")) === null ? null : ! $this->bool($adv),
                'channel' => $tree->value("{$path}.Channel"),
                'standard' => $standard,
                'band' => $band ?: $this->bandFromStandard($standard),
                'clients' => $tree->value("{$path}.TotalAssociations"),
                // Password tidak pernah dikirim ke frontend.
                'has_password' => filled($tree->value("{$path}.PreSharedKey.1.KeyPassphrase") ?? $tree->value("{$path}.KeyPassphrase")),
            ];
        }

        return $out;
    }

    private function bandFromStandard(?string $standard): ?string
    {
        if ($standard === null || $standard === '') {
            return null;
        }
        // Contoh nilai asli FiberHome: "bgn" (2.4 GHz), "a,n,ac" (5 GHz).
        $tokens = array_map('trim', preg_split('/[,\/]/', strtolower($standard)));
        if (array_intersect($tokens, ['a', 'ac', 'a/n/ac', 'anac']) !== []) {
            return '5GHz';
        }
        if (array_intersect($tokens, ['b', 'g', 'bg', 'bgn', 'gn', 'b/g/n']) !== [] || preg_match('/^[bgn]+$/', $tokens[0])) {
            return '2.4GHz';
        }

        return null; // mis. "ax" bisa di kedua band
    }

    private function has5g(array $ssids): ?bool
    {
        $bands = array_filter(array_column($ssids, 'band'));

        return $bands === [] ? null : in_array('5GHz', $bands, true);
    }

    private function wanIp(array $wans): ?string
    {
        foreach ($wans as $wan) {
            if ($wan['external_ip'] && $wan['external_ip'] !== '0.0.0.0') {
                return $wan['external_ip'];
            }
        }

        return null;
    }

    private function connectionRequestHost(ParameterTree $tree, string $root): ?string
    {
        $url = $tree->stringValue($root.'.ManagementServer.ConnectionRequestURL');

        return $url ? (parse_url($url, PHP_URL_HOST) ?: null) : null;
    }

    /**
     * Data dianggap belum lengkap bila objek utama atau detail penting belum pernah
     * dibaca dari ONT (umum terjadi setelah BOOTSTRAP: cache GenieACS dikosongkan).
     *
     * @return array{complete: bool, missing: list<string>}
     */
    private function completeness(ParameterTree $tree, string $root, array $wans, array $ssids): array
    {
        $missing = [];

        if ($tree->value($root.'.DeviceInfo.SoftwareVersion') === null) {
            $missing[] = 'DeviceInfo';
        }
        if (! $tree->has($root.'.WANDevice')) {
            $missing[] = 'WANDevice';
        } elseif ($wans !== [] && collect($wans)->every(fn ($w) => $w['connection_type'] === null || $w['status'] === null)) {
            $missing[] = 'Detail WAN';
        }
        if (! $tree->has($root.'.LANDevice')) {
            $missing[] = 'LANDevice';
        } else {
            if ($tree->instances($root.'.LANDevice.1.LANEthernetInterfaceConfig') === []) {
                $missing[] = 'Port LAN';
            }
            if ($ssids !== [] && collect($ssids)->every(fn ($s) => $s['ssid'] === null || $s['band'] === null)) {
                $missing[] = 'Detail WiFi';
            }
        }

        return ['complete' => $missing === [], 'missing' => $missing];
    }

    private function bool(mixed $v): bool
    {
        return in_array(strtolower((string) $v), ['1', 'true'], true);
    }

    /** Untuk dipakai di view / API bila perlu menyamarkan data mentah. */
    public static function redact(array $data): array
    {
        return Redactor::redact($data);
    }
}

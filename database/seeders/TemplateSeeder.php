<?php

namespace Database\Seeders;

use App\Models\ConfigurationTemplate;
use Illuminate\Database\Seeder;

/** Contoh template dari spesifikasi. Password tidak pernah disimpan. */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['HOTSPOT BRIDGE', 'bridge', 'WAN bridge VLAN 100, LAN 1-2 & SSID 1.', ['slot' => 'ip', 'vlan_id' => 100, 'service_list' => 'INTERNET'], [1, 2], [1], true],
            ['PPPoE HOME', 'pppoe', 'PPPoE VLAN 200, LAN 1 & SSID 1.', ['slot' => 'ppp', 'vlan_id' => 200, 'service_list' => 'INTERNET', 'nat_enabled' => true], [1], [1], false],
            ['PPPoE MULTI LAN', 'pppoe', 'PPPoE VLAN 200, LAN 1-4 & SSID 1.', ['slot' => 'ppp', 'vlan_id' => 200, 'service_list' => 'INTERNET', 'nat_enabled' => true], [1, 2, 3, 4], [1], false],
        ];

        foreach ($templates as [$name, $type, $desc, $wan, $lan, $wlan, $default]) {
            ConfigurationTemplate::firstOrCreate(['name' => $name], [
                'service_type' => $type, 'description' => $desc, 'is_default' => $default,
                'payload' => ['service_type' => $type, 'wan' => $wan + ['vlan_priority' => null, 'name' => null], 'binding' => ['lan' => $lan, 'wlan' => $wlan]],
            ]);
        }
    }
}

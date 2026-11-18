<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceType;

/** Permintaan konfigurasi dari wizard (sudah lolos validasi form). */
final class ConfigurationRequest
{
    /**
     * @param  array{mode: string, existing_path: ?string, slot: string, name: ?string, vlan_id: int, vlan_priority: ?int, service_list: string, nat_enabled: ?bool, pppoe_username: ?string, pppoe_password: ?string}  $wan
     * @param  list<array{index: int, ssid_name: ?string, password: ?string, security: ?string, enabled: ?bool, hidden: ?bool}>  $ssids
     * @param  array{lan: list<int>, wlan: list<int>}  $binding
     */
    public function __construct(
        public readonly ServiceType $serviceType,
        public readonly array $wan,
        public readonly array $ssids,
        public readonly array $binding,
        public readonly bool $confirmOverwrite = false,
    ) {}

    public static function fromArray(array $data): self
    {
        $w = $data['wan'] ?? [];

        return new self(
            ServiceType::from($data['service_type']),
            [
                'mode' => $w['mode'] ?? 'new',
                'existing_path' => $w['existing_path'] ?? null,
                'slot' => $w['slot'] ?? 'ip',
                'name' => isset($w['name']) && $w['name'] !== '' ? (string) $w['name'] : null,
                'vlan_id' => (int) ($w['vlan_id'] ?? 0),
                'vlan_priority' => isset($w['vlan_priority']) && $w['vlan_priority'] !== '' ? (int) $w['vlan_priority'] : null,
                'service_list' => $w['service_list'] ?? 'INTERNET',
                'nat_enabled' => isset($w['nat_enabled']) ? (bool) $w['nat_enabled'] : null,
                'pppoe_username' => $w['pppoe_username'] ?? null,
                'pppoe_password' => $w['pppoe_password'] ?? null,
            ],
            array_values(array_map(fn ($s) => [
                'index' => (int) $s['index'],
                'ssid_name' => isset($s['ssid_name']) && $s['ssid_name'] !== '' ? (string) $s['ssid_name'] : null,
                'password' => isset($s['password']) && $s['password'] !== '' ? (string) $s['password'] : null,
                'security' => $s['security'] ?? null,
                'enabled' => isset($s['enabled']) ? (bool) $s['enabled'] : null,
                'hidden' => isset($s['hidden']) ? (bool) $s['hidden'] : null,
            ], $data['ssids'] ?? [])),
            [
                'lan' => array_values(array_unique(array_map('intval', $data['binding']['lan'] ?? []))),
                'wlan' => array_values(array_unique(array_map('intval', $data['binding']['wlan'] ?? []))),
            ],
            (bool) ($data['confirm_overwrite'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'service_type' => $this->serviceType->value,
            'wan' => $this->wan,
            'ssids' => $this->ssids,
            'binding' => $this->binding,
            'confirm_overwrite' => $this->confirmOverwrite,
        ];
    }

    /** Salinan tanpa password (untuk disimpan setelah job selesai). */
    public function withoutSecrets(): array
    {
        $a = $this->toArray();
        $a['wan']['pppoe_password'] = null;
        foreach ($a['ssids'] as &$s) {
            $s['password'] = null;
        }

        return $a;
    }
}

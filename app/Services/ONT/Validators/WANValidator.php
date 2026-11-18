<?php

namespace App\Services\ONT\Validators;

use App\Enums\ServiceType;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\Mappers\VLANMapper;
use App\Services\ONT\Mappers\WANMapper;
use App\Services\Provisioning\ConfigurationRequest;
use App\Services\Provisioning\ProvisioningPlan;

final class WANValidator
{
    /**
     * @param  list<array<string, mixed>>  $existingWans  hasil driver->getWANConnections()
     */
    public static function validate(DeviceContext $ctx, ConfigurationRequest $req, array $existingWans, ProvisioningPlan $plan): void
    {
        $w = $req->wan;
        $cap = $ctx->capability();

        foreach (VLANMapper::validate($w['vlan_id'], $w['vlan_priority'], $cap?->vlan_min ?? 1, $cap?->vlan_max ?? 4094) as $e) {
            $plan->error($e);
        }

        if (! in_array($w['service_list'], WANMapper::SERVICE_LISTS, true)) {
            $plan->error('Service List harus INTERNET atau Other.');
        }
        if ($req->serviceType === ServiceType::Pppoe && $w['service_list'] !== 'INTERNET') {
            $plan->error('WAN PPPoE harus memakai Service List INTERNET.');
        }
        if ($w['name'] !== null && mb_strlen($w['name']) > 64) {
            $plan->error('Nama WAN maksimal 64 karakter.');
        }

        if ($req->serviceType === ServiceType::Pppoe) {
            if (blank($w['pppoe_username'])) {
                $plan->error('PPPoE Username wajib diisi.');
            } elseif (mb_strlen($w['pppoe_username']) > 64 || preg_match('/\s/', $w['pppoe_username'])) {
                $plan->error('PPPoE Username maksimal 64 karakter dan tanpa spasi.');
            }
            if (blank($w['pppoe_password']) && $w['mode'] === 'new') {
                $plan->error('PPPoE Password wajib diisi.');
            } elseif ($w['pppoe_password'] !== null && strlen($w['pppoe_password']) > 64) {
                $plan->error('PPPoE Password maksimal 64 karakter.');
            }
        }

        $target = null;
        if ($w['mode'] === 'existing') {
            $target = collect($existingWans)->firstWhere('path', $w['existing_path']);
            if (! $target) {
                $plan->error('WAN yang dipilih tidak ditemukan di ONT. Refresh parameter lalu pilih ulang.');
            } else {
                if (self::isManagementWan($target)) {
                    $plan->error("WAN \"{$target['name']}\" adalah WAN manajemen TR-069/VOIP. Mengubahnya bisa memutus koneksi ONT ke ACS, sehingga tidak diizinkan.");
                }
                if (! $req->confirmOverwrite) {
                    $plan->error("WAN \"{$target['name']}\" sudah dipakai. Centang konfirmasi untuk mengubah WAN yang sudah ada.");
                }
                if ($target['slot'] !== $w['slot']) {
                    $plan->error('Slot WAN yang dipilih tidak sesuai dengan WAN yang sudah ada.');
                }
            }
        } else {
            $max = $w['slot'] === 'ppp' ? $cap?->max_wan_ppp : $cap?->max_wan_ip;
            $count = count(array_filter($existingWans, fn ($x) => $x['slot'] === $w['slot']));
            if ($max !== null && $count >= $max) {
                $plan->error("WAN slot penuh: model ini maksimal {$max} WAN ".strtoupper($w['slot']).'.');
            }
        }

        // VLAN tidak boleh bentrok dengan WAN lain (mencegah WAN duplikat saat APPLY ditekan ulang).
        foreach ($existingWans as $existing) {
            if ($target && $existing['path'] === $target['path']) {
                continue;
            }
            if ($existing['vlan_id'] !== null && (int) $existing['vlan_id'] === $w['vlan_id']) {
                $plan->error("VLAN {$w['vlan_id']} sudah dipakai oleh WAN \"{$existing['name']}\" ({$existing['short_path']}). Pilih \"Gunakan WAN yang ada\" bila ingin mengubahnya.");
            }
        }
    }

    public static function isManagementWan(array $wan): bool
    {
        $haystack = strtoupper(($wan['name'] ?? '').' '.($wan['service_list'] ?? ''));

        return str_contains($haystack, 'TR069') || str_contains($haystack, 'TR-069');
    }
}

<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan key-value S.NET (pppoe_settings, wg_settings, redundant_settings).
 * Dibaca sekali per request lalu disimpan di memori.
 */
class SnetSettings
{
    /** @var array<string, array{table: string, key: string, value: string}> */
    private const GROUPS = [
        'pppoe' => ['table' => 'pppoe_settings', 'key' => 'setting_key', 'value' => 'setting_value'],
        'wireguard' => ['table' => 'wg_settings', 'key' => 'key', 'value' => 'value'],
        'redundant' => ['table' => 'redundant_settings', 'key' => 'key', 'value' => 'value'],
    ];

    /** @var array<string, array<string, string|null>> */
    private array $loaded = [];

    /** @return array<string, string|null> */
    public function all(string $group): array
    {
        $g = self::GROUPS[$group];

        return $this->loaded[$group] ??= DB::table($g['table'])->pluck($g['value'], $g['key'])->all();
    }

    public function get(string $group, string $key, ?string $default = null): ?string
    {
        $value = $this->all($group)[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** Kunci rahasia (API key payment gateway) disimpan terenkripsi. */
    public const SECRET_KEYS = ['midtrans_server_key', 'tripay_private_key', 'tripay_api_key', 'wg_server_privkey'];

    public function secret(string $group, string $key): ?string
    {
        $raw = $this->get($group, $key);

        return $raw === null ? null : rescue(fn () => Crypt::decryptString($raw), $raw, report: false);
    }

    /** @param  array<string, scalar|null>  $values */
    public function put(string $group, array $values): void
    {
        $g = self::GROUPS[$group];
        foreach ($values as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true) && filled($value)) {
                $value = Crypt::encryptString((string) $value);
            }
            DB::table($g['table'])->updateOrInsert([$g['key'] => $key], [$g['value'] => $value ?? '', 'updated_at' => now()]);
        }
        unset($this->loaded[$group]);
    }
}

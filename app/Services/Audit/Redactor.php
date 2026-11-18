<?php

namespace App\Services\Audit;

class Redactor
{
    public const MASK = '[REDACTED]';

    /**
     * Samarkan nilai secret secara rekursif. Kunci dicocokkan tanpa memperhatikan huruf besar,
     * dan juga mengenali path TR-069 (mis. "...PreSharedKey.1.KeyPassphrase").
     */
    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        // Format parameterValues GenieACS: [[path, value, type], ...]
        if (array_is_list($data) && isset($data[0]) && is_string($data[0]) && array_key_exists(1, $data) && count($data) <= 3) {
            if (self::isSecretKey($data[0])) {
                $data[1] = self::MASK;
            }

            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key) && ! is_array($value)) {
                $data[$key] = $value === null || $value === '' ? $value : self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function isSecretKey(string $key): bool
    {
        $normalized = strtolower($key);
        // Untuk path TR-069 ambil segmen terakhir: "...WANPPPConnection.1.Password" -> "password"
        $last = str_contains($normalized, '.') ? substr($normalized, strrpos($normalized, '.') + 1) : $normalized;

        foreach ((array) config('ontconfig.redact_keys') as $secret) {
            // "key" terlalu umum untuk kunci array biasa (mis. kunci mapping "wan.ip.vlan_id");
            // hanya dianggap rahasia bila berupa segmen terakhir path TR-069 (X_FH_Radius.Key, WEPKey).
            if ($secret === 'key' && ! str_contains($normalized, '.')) {
                continue;
            }
            if ($last === $secret || str_ends_with($last, $secret) || $normalized === $secret) {
                return true;
            }
        }

        return false;
    }
}

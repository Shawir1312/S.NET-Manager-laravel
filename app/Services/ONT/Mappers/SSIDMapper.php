<?php

namespace App\Services\ONT\Mappers;

final class SSIDMapper
{
    /** Mode keamanan yang diizinkan (spesifikasi): 11i, WPAand11i, None (open system). */
    public const SECURITY_MODES = ['11i', 'WPAand11i', 'None'];

    /** @return list<string> */
    public static function validate(array $ssid): array
    {
        $errors = [];
        $n = 'SSID '.$ssid['index'];

        if ($ssid['ssid_name'] !== null && (mb_strlen($ssid['ssid_name']) < 1 || mb_strlen($ssid['ssid_name']) > 32)) {
            $errors[] = "{$n}: nama WiFi harus 1-32 karakter.";
        }
        if ($ssid['security'] !== null && ! in_array($ssid['security'], self::SECURITY_MODES, true)) {
            $errors[] = "{$n}: mode keamanan tidak valid.";
        }
        if ($ssid['security'] === 'None' && $ssid['password'] !== null) {
            $errors[] = "{$n}: Open System tidak memakai password. Kosongkan password.";
        }
        if (in_array($ssid['security'], ['11i', 'WPAand11i'], true) && $ssid['password'] !== null) {
            $len = strlen($ssid['password']);
            if ($len < 8 || $len > 63) {
                $errors[] = "{$n}: password WiFi (WPA) harus 8-63 karakter.";
            }
        }
        if ($ssid['password'] !== null && $ssid['security'] === null) {
            $errors[] = "{$n}: pilih mode keamanan WPA untuk mengubah password.";
        }

        return $errors;
    }
}

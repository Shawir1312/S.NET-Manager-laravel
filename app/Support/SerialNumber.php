<?php

namespace App\Support;

/**
 * Serial Number GPON bisa tampil dalam dua bentuk:
 *  - Teks vendor + 8 hex  : HWTC9C31B89F  (tertulis di stiker ONT)
 *  - 16 hex               : 485754439C31B89F (dilaporkan sebagian ONT Huawei ke ACS)
 * Kedua bentuk mewakili ONT yang SAMA. Kelas ini membuat semua variannya
 * agar pencarian ke GenieACS tetap exact match.
 */
final class SerialNumber
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\-_.]{3,63}$/';

    public static function normalize(string $serial): string
    {
        return strtoupper(trim(preg_replace('/\s+/', '', $serial)));
    }

    public static function isValid(string $serial): bool
    {
        return preg_match(self::PATTERN, self::normalize($serial)) === 1;
    }

    /** @return list<string> */
    public static function variants(string $serial): array
    {
        $sn = self::normalize($serial);
        $variants = [$sn];

        // HWTC9C31B89F -> 485754439C31B89F
        if (preg_match('/^([A-Z]{4})([0-9A-F]{8})$/', $sn, $m)) {
            $variants[] = strtoupper(bin2hex($m[1])).$m[2];
        }

        // 485754439C31B89F -> HWTC9C31B89F (hanya jika 4 byte awal = huruf A-Z)
        if (preg_match('/^([0-9A-F]{8})([0-9A-F]{8})$/', $sn, $m)) {
            $prefix = hex2bin($m[1]);
            if ($prefix !== false && preg_match('/^[A-Z]{4}$/', $prefix)) {
                $variants[] = $prefix.$m[2];
            }
        }

        // ONT kadang melaporkan huruf kecil.
        foreach ($variants as $v) {
            $variants[] = strtolower($v);
        }

        return array_values(array_unique($variants));
    }

    /** Apakah dua SN merujuk ke ONT yang sama (memperhitungkan bentuk hex). */
    public static function equals(string $a, string $b): bool
    {
        return in_array(self::normalize($b), array_map([self::class, 'normalize'], self::variants($a)), true);
    }
}

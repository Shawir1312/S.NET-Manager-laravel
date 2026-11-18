<?php

namespace App\Support;

/** Konversi nilai agar sesuai tipe data yang dipakai ONT (mis. ZTE: VLANEnable = xsd:unsignedInt). */
final class Tr069Value
{
    public static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'xsd:boolean' => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true'], true),
            'xsd:unsignedInt', 'xsd:int', 'xsd:unsignedLong', 'xsd:long' => is_bool($value) ? (int) $value : (is_numeric($value) ? (int) $value : $value),
            'xsd:string' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            default => $value,
        };
    }
}

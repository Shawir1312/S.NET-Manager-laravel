<?php

namespace App\Services\ONT\Mappers;

final class VLANMapper
{
    public const PRIORITY_MIN = 0;

    public const PRIORITY_MAX = 7; // IEEE 802.1p

    /** @return list<string> pesan error */
    public static function validate(?int $vlanId, ?int $priority, int $min = 1, int $max = 4094): array
    {
        $errors = [];
        if ($vlanId === null || $vlanId === 0) {
            $errors[] = 'VLAN ID wajib diisi.';
        } elseif ($vlanId < $min || $vlanId > $max) {
            $errors[] = "VLAN ID {$vlanId} di luar rentang yang didukung perangkat ({$min}-{$max}).";
        }
        if ($priority !== null && ($priority < self::PRIORITY_MIN || $priority > self::PRIORITY_MAX)) {
            $errors[] = 'VLAN Priority harus 0-7 (802.1p).';
        }

        return $errors;
    }
}

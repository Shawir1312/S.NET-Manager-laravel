<?php

/*
 * Helper tampilan dari S.NET lama, dipakai oleh view yang disalin dari halaman PHP lama.
 */

if (! function_exists('format_price')) {
    function format_price(float|int|string|null $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}

if (! function_exists('format_bytes')) {
    function format_bytes(float|int|string|null $bytes, int $precision = 2): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $i), $i === 0 ? 0 : $precision).' '.$units[$i];
    }
}

if (! function_exists('format_durasi_clean')) {
    /** "2 Hari 3 Jam 5 Menit" (format laporan S.NET lama). */
    function format_durasi_clean(int $secs): string
    {
        if ($secs <= 0) {
            return '0 Menit';
        }
        $parts = array_filter([
            ($d = intdiv($secs, 86400)) ? "{$d} Hari" : null,
            ($h = intdiv($secs % 86400, 3600)) ? "{$h} Jam" : null,
            ($m = intdiv($secs % 3600, 60)) ? "{$m} Menit" : null,
        ]);

        return implode(' ', $parts) ?: '< 1 Menit';
    }
}

<?php

namespace App\Enums;

enum ModelSupportStatus: string
{
    /** Sudah diuji di ONT asli: provisioning produksi diizinkan. */
    case Supported = 'supported';
    /** Terdeteksi, mapping belum lengkap/terverifikasi: hanya simulasi. */
    case Testing = 'testing';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Supported => 'Didukung',
            self::Testing => 'Uji coba',
            self::Unsupported => 'Tidak didukung',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Supported => 'success',
            self::Testing => 'warning',
            self::Unsupported => 'secondary',
        };
    }
}

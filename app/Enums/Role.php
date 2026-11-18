<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Teknisi = 'teknisi';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Teknisi => 'Operator',
            self::Viewer => 'Viewer',
        };
    }

    /** Boleh mengubah konfigurasi ONT (provisioning). */
    public function canConfigure(): bool
    {
        return in_array($this, [self::Admin, self::Teknisi], true);
    }
}

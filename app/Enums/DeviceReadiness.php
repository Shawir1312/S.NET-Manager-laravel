<?php

namespace App\Enums;

/** Status hasil pencarian / pemeriksaan ONT di halaman Auto Configuration. */
enum DeviceReadiness: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Multiple = 'multiple';
    case Offline = 'offline';
    case Unsupported = 'unsupported';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Found => 'Device Found',
            self::NotFound => 'Device Not Found',
            self::Multiple => 'Lebih dari satu perangkat',
            self::Offline => 'Device Offline',
            self::Unsupported => 'Unsupported Model',
            self::Ready => 'Ready to Configure',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Found => 'info',
            self::Multiple, self::Unsupported => 'warning',
            self::Offline, self::NotFound => 'danger',
        };
    }
}

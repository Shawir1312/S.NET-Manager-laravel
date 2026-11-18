<?php

namespace App\Enums;

enum ServiceType: string
{
    case Bridge = 'bridge';
    case Pppoe = 'pppoe';

    public function label(): string
    {
        return match ($this) {
            self::Bridge => 'Hotspot / Bridge',
            self::Pppoe => 'PPPoE',
        };
    }
}

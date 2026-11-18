<?php

namespace App\Enums;

enum Vendor: string
{
    case FiberHome = 'fiberhome';
    case ZTE = 'zte';
    case Huawei = 'huawei';

    public function label(): string
    {
        return match ($this) {
            self::FiberHome => 'FiberHome',
            self::ZTE => 'ZTE',
            self::Huawei => 'Huawei',
        };
    }

    /**
     * Nilai DeviceID.Manufacturer yang dilaporkan ONT ke GenieACS.
     * Diambil dari log GenieACS asli; tambahkan di sini bila ada OEM lain.
     *
     * @return list<string>
     */
    public function manufacturerAliases(): array
    {
        return match ($this) {
            self::FiberHome => ['FiberHome', 'FIBERHOME', 'Fiberhome'],
            self::ZTE => ['ZTE', 'zte'],
            self::Huawei => ['Huawei Technologies Co., Ltd', 'HUAWEI', 'Huawei'],
        };
    }

    public static function fromManufacturer(?string $manufacturer): ?self
    {
        if ($manufacturer === null) {
            return null;
        }

        foreach (self::cases() as $vendor) {
            if (in_array(trim($manufacturer), $vendor->manufacturerAliases(), true)) {
                return $vendor;
            }
        }

        return null;
    }
}

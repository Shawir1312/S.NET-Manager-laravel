<?php

namespace App\Services\ONT;

use App\Enums\Vendor;
use App\Services\ONT\Drivers\FiberHome\FiberHomeDriver;
use App\Services\ONT\Drivers\Huawei\HuaweiDriver;
use App\Services\ONT\Drivers\ZTE\ZTEDriver;
use App\Services\Provisioning\ProvisioningException;
use Illuminate\Contracts\Container\Container;

/** Memilih driver vendor yang tepat. */
class ONTManager
{
    public function __construct(private readonly Container $container) {}

    public function driverFor(DetectionResult $detection): ONTInterface
    {
        return match ($detection->vendor) {
            Vendor::FiberHome => $this->container->make(FiberHomeDriver::class),
            Vendor::ZTE => $this->container->make(ZTEDriver::class),
            Vendor::Huawei => $this->container->make(HuaweiDriver::class),
            null => throw new ProvisioningException('Unsupported Model: '.$detection->message(), 'UNSUPPORTED_MODEL'),
        };
    }
}

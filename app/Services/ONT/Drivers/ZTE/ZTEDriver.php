<?php

namespace App\Services\ONT\Drivers\ZTE;

use App\Services\ONT\DeviceContext;
use App\Services\ONT\Drivers\AbstractDriver;

class ZTEDriver extends AbstractDriver
{
    /** Model ZTE yang memerlukan path WAN ikut dalam binding. */
    public const WAN_PLUS_LAN_MODELS = ['F670L'];

    /**
     * KHUSUS ZTE F670L: binding membutuhkan WAN Interface (WANIPConnection.N untuk WAN IP,
     * WANPPPConnection.N untuk WAN PPP) + LAN/WLAN pilihan teknisi. Tidak berlaku untuk
     * model ZTE lain, FiberHome, maupun Huawei.
     */
    protected function bindingIncludesWan(DeviceContext $ctx): bool
    {
        return in_array(strtoupper((string) $ctx->detection->productClass), self::WAN_PLUS_LAN_MODELS, true);
    }
}

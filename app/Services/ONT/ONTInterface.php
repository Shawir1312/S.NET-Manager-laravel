<?php

namespace App\Services\ONT;

use App\Services\Provisioning\ConfigurationRequest;
use App\Services\Provisioning\ProvisioningPlan;
use App\Services\Provisioning\WanTarget;

/**
 * Kontrak driver vendor. Driver TIDAK memanggil GenieACS: ia membaca DeviceContext
 * dan menambahkan langkah ke ProvisioningPlan.
 */
interface ONTInterface
{
    /** @return array<string, mixed> */
    public function getDeviceInfo(DeviceContext $ctx): array;

    /** @return array<string, mixed> */
    public function getCapabilities(DeviceContext $ctx): array;

    /** @return list<array<string, mixed>> */
    public function getWANConnections(DeviceContext $ctx): array;

    public function createWANBridge(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void;

    public function createWANPPPoE(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void;

    public function configureVLAN(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void;

    public function configureSSID(DeviceContext $ctx, ConfigurationRequest $req, ProvisioningPlan $plan): void;

    public function configureBinding(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void;

    /**
     * Bandingkan nilai di ONT (setelah dibaca ulang) dengan nilai yang dikirim.
     *
     * @param  list<array{path: string, value: mixed, type: string, key: string, secret: bool}>  $applied
     * @return list<array{path: string, key: string, expected: mixed, actual: mixed, status: string}>
     */
    public function verifyConfiguration(DeviceContext $ctx, array $applied): array;

    /** Susun seluruh rencana konfigurasi (validasi + semua langkah). */
    public function buildPlan(DeviceContext $ctx, ConfigurationRequest $req): ProvisioningPlan;
}

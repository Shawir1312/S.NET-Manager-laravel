<?php

namespace App\Services\ONT\Validators;

use App\Enums\ModelSupportStatus;
use App\Services\ONT\DeviceContext;
use App\Services\Provisioning\ProvisioningPlan;

final class ONTValidator
{
    /** Model harus dikenali & tidak "unsupported". */
    public static function supported(DeviceContext $ctx, ProvisioningPlan $plan): bool
    {
        if (! $ctx->detection->isSupported()) {
            $plan->error('Unsupported Model: '.$ctx->detection->message());

            return false;
        }

        return true;
    }

    /**
     * Mode produksi: model harus "supported" dan SEMUA parameter yang dipakai sudah terverifikasi.
     *
     * @return list<string>
     */
    public static function productionBlockers(DeviceContext $ctx, ProvisioningPlan $plan): array
    {
        $blockers = [];
        if ($ctx->model()?->status !== ModelSupportStatus::Supported) {
            $blockers[] = "Model {$ctx->modelLabel()} belum berstatus \"Didukung\" (masih uji coba). Ubah status di Device Models setelah diuji di ONT asli.";
        }
        if ($plan->unverifiedKeys() !== []) {
            $blockers[] = 'Parameter belum terverifikasi di ONT asli: '.implode(', ', $plan->unverifiedKeys()).'.';
        }

        return $blockers;
    }

    public static function interfaceExists(DeviceContext $ctx, string $type, int $n): bool
    {
        $object = $type === 'lan' ? 'LANEthernetInterfaceConfig' : 'WLANConfiguration';

        return in_array($n, $ctx->tree->instances($ctx->root().".LANDevice.1.{$object}"), true);
    }
}

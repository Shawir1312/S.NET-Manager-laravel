<?php

namespace App\Jobs;

use App\Models\OntDevice;
use App\Models\User;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Memulai job provisioning lanjutan (mis. Hotspot setelah PPPoE) begitu ONT tidak
 * sedang dikonfigurasi. Input berisi password -> payload job dienkripsi.
 */
class StartQueuedProvisioning implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 40;

    /** @param  array<string, mixed>  $input */
    public function __construct(
        public int $deviceId,
        public array $input,
        public int $userId,
        public ?int $templateId = null,
    ) {}

    public function handle(ProvisioningService $provisioning): void
    {
        $device = OntDevice::find($this->deviceId);
        $user = User::find($this->userId);
        if (! $device || ! $user) {
            return;
        }

        try {
            $provisioning->start($device, $this->input, $user, $this->templateId);
        } catch (ProvisioningException $e) {
            if ($e->errorCode !== 'JOB_RUNNING') {
                throw $e;
            }
            $this->release(15); // job sebelumnya masih jalan; coba lagi nanti
        }
    }
}

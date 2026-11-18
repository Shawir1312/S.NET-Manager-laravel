<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\ConfigurationJob;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunProvisioningJob implements ShouldQueue
{
    use Queueable;

    /** Provisioning tidak boleh diulang otomatis (bisa membuat WAN dobel). */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $configurationJobId)
    {
        $this->onQueue('provisioning');
    }

    public function handle(ProvisioningService $service): void
    {
        $job = ConfigurationJob::find($this->configurationJobId);
        if ($job) {
            $service->execute($job);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $job = ConfigurationJob::find($this->configurationJobId);
        if ($job && ! $job->status->isFinal()) {
            $job->update([
                'status' => JobStatus::Failed, 'finished_at' => now(),
                'error_code' => 'WORKER_FAILED', 'error_message' => 'Worker provisioning berhenti sebelum job selesai (timeout/crash).',
            ]);
        }
    }
}

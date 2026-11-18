<?php

namespace App\Services\ONT;

use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use App\Models\OntModel;

final class DetectionResult
{
    public function __construct(
        public readonly ?Vendor $vendor,
        public readonly ?OntModel $model,
        public readonly ?string $manufacturer,
        public readonly ?string $productClass,
        public readonly ?string $softwareVersion,
        public readonly ?string $hardwareVersion,
    ) {}

    /** Model dikenali dan tidak ditandai "unsupported". */
    public function isSupported(): bool
    {
        return $this->vendor !== null
            && $this->model !== null
            && $this->model->status !== ModelSupportStatus::Unsupported;
    }

    /** Model dikenali tetapi mapping belum terverifikasi di ONT asli. */
    public function isSimulationOnly(): bool
    {
        return $this->isSupported() && $this->model->status !== ModelSupportStatus::Supported;
    }

    public function message(): string
    {
        if ($this->vendor === null) {
            return "Vendor \"{$this->manufacturer}\" belum didukung. Aplikasi mendukung FiberHome, ZTE, dan Huawei.";
        }
        if ($this->model === null) {
            return "Model {$this->vendor->label()} {$this->productClass} (firmware {$this->softwareVersion}) belum terdaftar di Device Models.";
        }
        if ($this->model->status === ModelSupportStatus::Unsupported) {
            return "Model {$this->model->model_name} ditandai tidak didukung.";
        }
        if ($this->isSimulationOnly()) {
            return "Model {$this->model->model_name} dikenali, tetapi mapping parameternya belum diverifikasi di ONT asli. Konfigurasi hanya bisa dijalankan di mode simulasi.";
        }

        return "Model {$this->model->model_name} didukung.";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor?->value,
            'vendor_label' => $this->vendor?->label() ?? ($this->manufacturer ?: 'Tidak dikenal'),
            'model_id' => $this->model?->id,
            'model_name' => $this->model?->model_name,
            'model_status' => $this->model?->status->value,
            'supported' => $this->isSupported(),
            'simulation_only' => $this->isSimulationOnly(),
            'message' => $this->message(),
        ];
    }
}

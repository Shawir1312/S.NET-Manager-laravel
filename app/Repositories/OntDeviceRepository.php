<?php

namespace App\Repositories;

use App\Models\OntDevice;
use App\Services\GenieACS\GenieACSService;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\DetectionResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OntDeviceRepository
{
    /** Simpan / perbarui cache lokal dari data GenieACS. */
    public function upsertFromGenieAcs(ParameterTree $tree, DetectionResult $detection, ?array $info = null): OntDevice
    {
        $id = $tree->deviceId();

        $attributes = [
            'serial_number' => $id['serial_number'],
            'genie_acs_setting_id' => GenieACSService::currentSetting()?->id,
            'manufacturer' => $id['manufacturer'],
            'vendor' => $detection->vendor,
            'oui' => $id['oui'],
            'product_class' => $id['product_class'],
            'ont_model_id' => $detection->model?->id,
            'last_inform_at' => $tree->lastInform(),
            'synced_at' => now(),
        ];
        // Jangan menimpa versi yang sudah diketahui dengan null dari proyeksi ringan.
        if ($detection->hardwareVersion !== null) {
            $attributes['hardware_version'] = $detection->hardwareVersion;
        }
        if ($detection->softwareVersion !== null) {
            $attributes['software_version'] = $detection->softwareVersion;
        }
        if ($info !== null) {
            $attributes['ip_address'] = $info['ip_address'] ?? $info['tr069_ip'] ?? null;
            $attributes['parameters_snapshot'] = [
                'counts' => $info['counts'],
                'model_name' => $info['model_name'],
                'completeness' => $info['completeness'],
                'parameter_count' => $info['parameter_count'],
            ];
        }

        // SN unik di aplikasi: jika SN yang sama terdaftar dengan _id lain
        // (mis. ONT dipindah/ganti OUI), baris lama diperbarui, bukan diduplikasi.
        $device = OntDevice::where('genieacs_id', $tree->id())->first()
            ?? OntDevice::where('serial_number', $id['serial_number'])->first()
            ?? new OntDevice;

        $device->fill($attributes + ['genieacs_id' => $tree->id()]);
        $device->genieacs_id = $tree->id();
        $device->save();

        return $device;
    }

    public function recordOnlineCheck(OntDevice $device, bool $online): void
    {
        $device->forceFill(['last_online_check_at' => now(), 'last_online_result' => $online])->save();
    }

    /**
     * @param  array{q?: ?string, vendor?: ?string, status?: ?string}  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $threshold = now()->subSeconds((int) config('ontconfig.online_threshold_seconds'));

        return OntDevice::query()
            ->with('ontModel:id,model_name,status')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('serial_number', 'like', $term)
                    ->orWhere('product_class', 'like', $term)
                    ->orWhere('ip_address', 'like', $term));
            })
            ->when($filters['vendor'] ?? null, fn ($q, $v) => $v === 'other' ? $q->whereNull('vendor') : $q->where('vendor', $v))
            ->when(($filters['status'] ?? null) === 'online', fn ($q) => $q->where('last_inform_at', '>=', $threshold))
            ->when(($filters['status'] ?? null) === 'offline', fn ($q) => $q->where(fn ($w) => $w->whereNull('last_inform_at')->orWhere('last_inform_at', '<', $threshold)))
            ->orderByDesc('last_inform_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}

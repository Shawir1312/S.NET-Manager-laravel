<?php

namespace App\Services\ONT;

use App\Models\OntModel;
use App\Models\OntParameterMapping;
use App\Services\GenieACS\ParameterTree;

/**
 * Verifikasi mapping parameter secara OTOMATIS dari data ONT asli:
 *  - path  : saat ONT dicari / di-refresh, path yang ADA di ONT ditandai verified.
 *  - write : setelah job produksi, parameter yang ditulis & dibaca ulang COCOK dinaikkan ke level write.
 * Tingkat verifikasi tidak pernah diturunkan otomatis.
 */
class MappingVerifier
{
    /** @var list<string> tipe data yang disesuaikan pada verifyPaths() terakhir */
    public array $typesSynced = [];

    /** @return list<string> kunci mapping yang baru terverifikasi */
    public function verifyPaths(OntModel $model, ParameterTree $tree): array
    {
        $this->typesSynced = [];
        $serial = (string) $tree->deviceId()['serial_number'];
        $firmware = $tree->stringValue($tree->root().'.DeviceInfo.SoftwareVersion') ?? '?';
        $date = now()->format('Y-m-d H:i');
        $promoted = [];

        foreach ($model->parameterMappings()->get() as $mapping) {
            /** @var OntParameterMapping $mapping */
            $found = $tree->matchTemplate($mapping->path_template);
            if ($found === null) {
                continue;
            }

            // Pakai tipe data ASLI dari ONT (ZTE memakai xsd:unsignedInt untuk VLANEnable, bukan xsd:boolean).
            $realType = $tree->parameter($found)['type'] ?? null;
            if ($realType && $realType !== $mapping->value_type) {
                $mapping->forceFill(['value_type' => $realType])->save();
                $this->typesSynced[] = "{$mapping->key}: {$realType}";
            }
            if ($mapping->promote('path', $serial, "Otomatis: path ditemukan di ONT {$serial} FW {$firmware} ({$date}), contoh {$found}")) {
                $promoted[] = $mapping->key;
            }
        }

        return $promoted;
    }

    /**
     * @param  list<array{path: string, key: string, status: string}>  $results  hasil verifyConfiguration()
     * @return list<string>
     */
    public function verifyWrites(OntModel $model, array $results, string $serial, string $jobUuid): array
    {
        $matched = collect($results)->where('status', 'match')->pluck('key')->unique();
        $promoted = [];

        foreach ($model->parameterMappings()->whereIn('key', $matched)->get() as $mapping) {
            if ($mapping->promote('write', $serial, "Otomatis: ditulis & dibaca ulang cocok di ONT {$serial} (job {$jobUuid}, ".now()->format('Y-m-d H:i').')')) {
                $promoted[] = $mapping->key;
            }
        }

        return $promoted;
    }
}

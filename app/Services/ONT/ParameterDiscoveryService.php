<?php

namespace App\Services\ONT;

use App\Models\OntDevice;
use App\Services\Audit\AuditLogger;
use App\Services\GenieACS\GenieACSService;
use App\Services\ONT\Mappers\WANMapper;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;

/**
 * Menemukan parameter objek WAN yang BELUM ADA di ONT (mis. WANPPPConnection) tanpa
 * mengubah konfigurasi: AddObject objek KOSONG sementara -> baca parameter & tipenya
 * -> verifikasi mapping otomatis -> DeleteObject. Tidak ada SetParameterValues.
 */
class ParameterDiscoveryService
{
    public function __construct(
        private readonly GenieACSService $acs,
        private readonly ONTDetector $detector,
        private readonly MappingVerifier $verifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{object: string, verified: list<string>, types_synced: list<string>, deleted: bool, message: string}
     */
    public function discoverWan(OntDevice $device, string $slot): array
    {
        if (! ProvisioningService::isProduction()) {
            throw new ProvisioningException('Discovery menulis objek sementara ke ONT, jadi hanya tersedia di mode produksi.', 'NOT_PRODUCTION');
        }

        $id = $device->genieacs_id;
        $online = $this->acs->getDeviceConnectionStatus($id);
        if (! $online['online']) {
            throw new ProvisioningException('ONT offline: '.$online['message'], 'DEVICE_OFFLINE');
        }

        $tree = $this->acs->getDeviceDetails($id);
        $detection = $this->detector->detect($tree);
        $model = $detection->model ?? throw new ProvisioningException('Model ONT belum terdaftar. Cari ONT ini dulu di Auto Configuration.', 'UNSUPPORTED_MODEL');

        $root = $tree->root();
        $base = "{$root}.WANDevice.1.WANConnectionDevice";
        $strategy = $model->capability?->extra['new_wan_strategy'] ?? 'new_wcd';
        $created = [];
        $object = null;

        try {
            $this->acs->refreshDeviceParameters($id, $base);
            if ($strategy === 'existing_wcd') {
                $wcd = $this->acs->getDeviceDetails($id)->instances($base)[0]
                    ?? throw new ProvisioningException('Tidak ada WANConnectionDevice di ONT.', 'NO_WCD');
            } else {
                $wcd = $this->addInstance($id, $base, $created);
            }

            $parent = "{$base}.{$wcd}.".WANMapper::objectName($slot);
            $i = $this->addInstance($id, $parent, $created);
            $object = "{$parent}.{$i}";

            $this->acs->refreshDeviceParameters($id, $object);
            $verified = $this->verifier->verifyPaths($model, $this->acs->getDeviceDetails($id));
            $types = $this->verifier->typesSynced;
        } finally {
            // Selalu hapus objek sementara (terdalam dulu), apa pun hasilnya.
            $deleted = true;
            foreach (array_reverse($created) as $path) {
                $deleted = $this->acs->deleteObject($id, $path)['state'] === 'done' && $deleted;
            }
            if ($created !== []) {
                $this->acs->refreshDeviceParameters($id, $base);
            }
        }

        $this->audit->log('ont.discover', $device, [
            'slot' => $slot, 'object' => $object, 'verified' => $verified, 'types_synced' => $types, 'deleted' => $deleted,
        ]);

        $message = count($verified).' parameter '.strtoupper($slot).' terverifikasi dari objek sementara '.str_replace("{$root}.WANDevice.1.", '', (string) $object).'. '
            .($deleted ? 'Objek sementara sudah dihapus.' : 'PERINGATAN: objek sementara gagal dihapus, cek ONT.');

        return ['object' => (string) $object, 'verified' => $verified, 'types_synced' => $types, 'deleted' => $deleted, 'message' => $message];
    }

    /** AddObject lalu kembalikan nomor instance barunya. */
    private function addInstance(string $id, string $parent, array &$created): int
    {
        $before = $this->acs->getDeviceDetails($id)->instances($parent);
        $r = $this->acs->addObject($id, $parent);
        if ($r['state'] !== 'done') {
            $why = $r['state'] === 'timeout' ? 'timeout' : (($r['fault']['code'] ?? '?').' '.($r['fault']['message'] ?? ''));
            throw new ProvisioningException("AddObject {$parent} ditolak ONT ({$why}).", 'ADD_OBJECT_FAILED');
        }
        $this->acs->refreshDeviceParameters($id, $parent);
        $new = array_values(array_diff($this->acs->getDeviceDetails($id)->instances($parent), $before));
        if (count($new) !== 1) {
            throw new ProvisioningException("Instance baru {$parent} tidak ditemukan setelah AddObject.", 'ADD_OBJECT_FAILED');
        }
        $created[] = "{$parent}.{$new[0]}";

        return $new[0];
    }
}

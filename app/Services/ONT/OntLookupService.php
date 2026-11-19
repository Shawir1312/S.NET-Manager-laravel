<?php

namespace App\Services\ONT;

use App\Enums\DeviceReadiness;
use App\Models\ConfigurationJob;
use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Repositories\OntDeviceRepository;
use App\Services\Audit\AuditLogger;
use App\Services\GenieACS\GenieACSService;
use App\Services\GenieACS\ParameterTree;
use App\Support\SerialNumber;

/**
 * Orkestrasi alur "Input SN": cari di GenieACS -> validasi -> deteksi vendor/model
 * -> simpan cache -> (terpisah) cek online -> tentukan kesiapan.
 */
class OntLookupService
{
    public function __construct(
        private readonly GenieACSService $acs,
        private readonly ONTDetector $detector,
        private readonly DeviceInfoBuilder $infoBuilder,
        private readonly OntDeviceRepository $devices,
        private readonly AuditLogger $audit,
        private readonly MappingVerifier $verifier,
        private readonly ModelRegistrar $registrar,
    ) {}

    /**
     * @return array{status: string, label: string, message: string, devices: list<array<string, mixed>>}
     */
    public function search(string $serialNumber): array
    {
        $sn = SerialNumber::normalize($serialNumber);
        $found = $this->acs->findDeviceBySerialNumber($sn);

        $this->audit->log('ont.search', null, ['serial_number' => $sn, 'results' => count($found)]);

        if ($found === []) {
            return $this->result(DeviceReadiness::NotFound, "ONT dengan SN {$sn} tidak ditemukan di GenieACS. Pastikan ONT sudah terhubung ke ACS (ACS URL & kredensial benar) dan SN diketik dengan benar.");
        }

        $rows = array_map(fn (ParameterTree $tree) => $this->summarize($tree), $found);

        if (count($rows) > 1) {
            return $this->result(DeviceReadiness::Multiple, 'Ditemukan '.count($rows).' perangkat dengan SN ini. Pilih perangkat yang benar sebelum melanjutkan.', $rows);
        }

        return $this->result(DeviceReadiness::Found, 'Perangkat ditemukan. Memeriksa status online…', $rows);
    }

    /**
     * Cari SN di SEMUA server GenieACS aktif: di server mana ONT ini terdaftar dan apakah online.
     *
     * @return list<array{server_id: int, server: string, serial_number: ?string, product: string, online: bool, last_inform: ?string}>
     */
    public static function locate(string $serialNumber): array
    {
        $since = now()->subSeconds((int) config('ontconfig.online_threshold_seconds'));
        $out = [];
        foreach (GenieAcsSetting::where('is_active', true)->orderBy('id')->get() as $setting) {
            $trees = rescue(fn () => (new GenieACSService($setting))->findDeviceBySerialNumber($serialNumber), [], report: false);
            foreach ($trees as $tree) {
                $id = $tree->deviceId();
                $out[] = [
                    'server_id' => $setting->id, 'server' => $setting->name,
                    'serial_number' => $id['serial_number'],
                    'product' => trim(($id['manufacturer'] ?? '').' '.($id['product_class'] ?? '')),
                    'online' => (bool) $tree->lastInform()?->gte($since),
                    'last_inform' => $tree->lastInform()?->toIso8601String(),
                ];
            }
        }

        return $out;
    }

    /**
     * Detail lengkap satu perangkat (membaca dokumen penuh dari GenieACS).
     *
     * @return array{device: OntDevice, info: array<string, mixed>, detection: array<string, mixed>}
     */
    public function inspect(OntDevice $device): array
    {
        $tree = $this->acs->getDeviceDetails($device->genieacs_id);
        $detection = $this->detector->detect($tree);

        // Model baru dari vendor yang didukung -> daftarkan otomatis.
        $registered = false;
        if ($detection->vendor && ! $detection->model && $this->registrar->ensure($detection, $tree)) {
            $detection = $this->detector->detect($tree);
            $registered = true;
            $this->audit->log('model.auto_registered', $detection->model, ['product_class' => $detection->productClass, 'serial_number' => $device->serial_number]);
        }

        // Verifikasi otomatis: path yang ada di ONT ini -> verified (tersimpan di Device Models).
        $autoVerified = $detection->model ? $this->verifier->verifyPaths($detection->model, $tree) : [];
        if ($autoVerified !== [] || $this->verifier->typesSynced !== []) {
            $this->audit->log('model.auto_verified', $detection->model, [
                'level' => 'path', 'keys' => $autoVerified, 'types_synced' => $this->verifier->typesSynced, 'serial_number' => $device->serial_number,
            ]);
            $detection = $this->detector->detect($tree);
        }

        $info = $this->infoBuilder->build($tree, $detection);
        $device = $this->devices->upsertFromGenieAcs($tree, $detection, $info);

        return ['device' => $device, 'info' => $info, 'detection' => $detection->toArray() + [
            'auto_verified' => $autoVerified,
            'auto_registered' => $registered,
        ]];
    }

    /**
     * Cek online sekarang + tentukan kesiapan konfigurasi.
     *
     * @return array<string, mixed>
     */
    public function checkReadiness(OntDevice $device): array
    {
        $tree = $this->acs->getDeviceDetails($device->genieacs_id);
        $detection = $this->detector->detect($tree);
        $connection = $this->acs->getDeviceConnectionStatus($device->genieacs_id, $tree->root());
        $this->devices->recordOnlineCheck($device, $connection['online']);

        $this->audit->log('ont.online_check', $device, ['online' => $connection['online']]);

        $status = match (true) {
            ! $connection['online'] => DeviceReadiness::Offline,
            ! $detection->isSupported() => DeviceReadiness::Unsupported,
            default => DeviceReadiness::Ready,
        };

        $message = match ($status) {
            DeviceReadiness::Offline => $connection['message'].' Konfigurasi tidak bisa dikirim sampai ONT online.',
            DeviceReadiness::Unsupported => $detection->message().' Konfigurasi tidak akan dikirim.',
            default => $detection->isSimulationOnly()
                ? 'ONT online. '.$detection->message()
                : 'ONT online dan siap dikonfigurasi.',
        };

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'badge' => $status->badge(),
            'message' => $message,
            'online' => $connection['online'],
            'checked_at' => $connection['checked_at'],
            'uptime_seconds' => $connection['uptime'],
            'simulation_only' => $detection->isSimulationOnly(),
            'detection' => $detection->toArray(),
        ];
    }

    /**
     * Baca ulang objek utama dari ONT. Objek kecil dulu supaya hasil cepat terlihat.
     *
     * @return array{completed: bool, results: list<array<string, mixed>>}
     */
    public function refresh(OntDevice $device): array
    {
        $tree = $this->acs->getDeviceDetails($device->genieacs_id);
        $root = $tree->root();
        $results = [];

        foreach (["{$root}.DeviceInfo", "{$root}.WANDevice", "{$root}.LANDevice"] as $object) {
            $r = $this->acs->refreshDeviceParameters($device->genieacs_id, $object, 30);
            $results[] = ['object' => $object] + $r;
            if (! $r['completed']) {
                break; // ONT tidak merespons, jangan menumpuk task berikutnya.
            }
        }

        $completed = collect($results)->every(fn ($r) => $r['completed']);
        $this->audit->log('ont.refresh', $device, ['completed' => $completed, 'objects' => array_column($results, 'object')]);

        return ['completed' => $completed, 'results' => $results];
    }

    /**
     * Sinkronkan daftar perangkat dari GenieACS ke cache lokal.
     *
     * @return array{synced: int, pages: int}
     */
    /**
     * Sinkron semua ONT dari server GenieACS aktif: identitas + data live (IP, SSID, redaman).
     * ONT milik server ini yang tidak lagi ada di ACS (mis. setelah ganti server) dihapus;
     * yang punya riwayat konfigurasi hanya dilepas dari server agar riwayatnya tetap utuh.
     *
     * @return array{synced: int, removed: int, pages: int}
     */
    public function syncAll(int $pageSize = 500, int $maxPages = 200): array
    {
        $start = now()->startOfSecond();
        $synced = 0;
        $complete = false;
        for ($page = 0; $page < $maxPages; $page++) {
            $batch = $this->acs->listDevices($pageSize, $page * $pageSize, OntLiveInfo::PROJECTION);
            foreach ($batch as $tree) {
                if (blank($tree->deviceId()['serial_number'])) {
                    continue;
                }
                $device = $this->devices->upsertFromGenieAcs($tree, $this->detector->detect($tree));
                $live = OntLiveInfo::fromTree($tree);
                $device->forceFill([
                    'ip_address' => $live['ip_address'] ?? $device->ip_address,
                    'parameters_snapshot' => array_merge((array) $device->parameters_snapshot, [
                        'ssid_24' => $live['ssid_24'], 'ssid_5g' => $live['ssid_5g'], 'rx' => $live['rx'], 'tx' => $live['tx'],
                        'tags' => array_values(array_filter((array) ($tree->raw()['_tags'] ?? []), 'is_string')),
                    ]),
                ])->save();
                $synced++;
            }
            if (count($batch) < $pageSize) {
                $complete = true;
                break;
            }
        }

        $removed = $complete ? $this->pruneMissing($start) : 0;
        $this->audit->log('ont.sync', null, ['synced' => $synced, 'removed' => $removed]);

        return ['synced' => $synced, 'removed' => $removed, 'pages' => $page + 1];
    }

    /** ONT server aktif yang tidak terlihat pada sinkron penuh terakhir. */
    private function pruneMissing(\DateTimeInterface $start): int
    {
        $serverId = GenieACSService::currentSetting()?->id;
        $stale = OntDevice::where('genie_acs_setting_id', $serverId)
            ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', $start))->get();
        foreach ($stale as $device) {
            ConfigurationJob::where('ont_device_id', $device->id)->exists()
                ? $device->forceFill(['genie_acs_setting_id' => null])->save()
                : $device->delete();
        }

        return $stale->count();
    }

    /** @return array<string, mixed> */
    private function summarize(ParameterTree $tree): array
    {
        $detection = $this->detector->detect($tree);
        $device = $this->devices->upsertFromGenieAcs($tree, $detection);
        $id = $tree->deviceId();

        return [
            'id' => $device->id,
            'genieacs_id' => $tree->id(),
            'serial_number' => $id['serial_number'],
            'manufacturer' => $id['manufacturer'],
            'product_class' => $id['product_class'],
            'oui' => $id['oui'],
            'last_inform' => $tree->lastInform()?->toIso8601String(),
            'detection' => $detection->toArray(),
        ];
    }

    private function result(DeviceReadiness $status, string $message, array $devices = []): array
    {
        return ['status' => $status->value, 'label' => $status->label(), 'badge' => $status->badge(), 'message' => $message, 'devices' => $devices];
    }
}

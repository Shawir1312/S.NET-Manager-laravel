<?php

namespace App\Services\GenieACS;

use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Services\GenieACS\Exceptions\DeviceNotFoundException;
use App\Services\GenieACS\Exceptions\GenieACSAuthException;
use App\Services\GenieACS\Exceptions\GenieACSException;
use App\Services\GenieACS\Exceptions\GenieACSUnreachableException;
use App\Support\SerialNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya pintu aplikasi ke GenieACS NBI.
 *
 * Phase 1: koneksi & testConnection().
 * Phase 2: pencarian SN, detail device, cek online (Connection Request), refresh parameter, status task.
 * Phase 4-5: setParameterValues, addObject, deleteObject, createProvisioningTask.
 *
 * Referensi NBI: GET /devices/?query=, POST /devices/<id>/tasks?connection_request&timeout=,
 * GET /tasks/?query=, DELETE /tasks/<id>, GET /faults/?query=.
 */
class GenieACSService
{
    /** Timeout HTTP sementara untuk satu request (detik). */
    private ?int $requestTimeoutOverride = null;

    public function __construct(private readonly GenieAcsSetting $setting) {}

    public static function fromActiveSetting(): self
    {
        $setting = self::currentSetting();
        if (! $setting) {
            throw new GenieACSException('Pengaturan GenieACS belum dibuat. Buka menu GenieACS Settings.', 'GENIEACS_NOT_CONFIGURED');
        }

        return new self($setting);
    }

    /** Pilih server untuk resolusi GenieACSService berikutnya dari container (mis. per router). */
    public static function use(GenieAcsSetting $setting): void
    {
        app()->instance(GenieAcsSetting::class.'.current', $setting);
    }

    /** Server yang sedang dipilih, atau server aktif terbaru. */
    public static function currentSetting(): ?GenieAcsSetting
    {
        return app()->bound(GenieAcsSetting::class.'.current') ? app(GenieAcsSetting::class.'.current') : GenieAcsSetting::active();
    }

    /** Server tempat ONT ini terdaftar; jatuh ke server terpilih/aktif bila belum tercatat. */
    public static function forDevice(OntDevice $device): self
    {
        $setting = $device->genie_acs_setting_id ? GenieAcsSetting::find($device->genie_acs_setting_id) : null;

        return $setting ? new self($setting) : app(self::class);
    }

    public function setting(): GenieAcsSetting
    {
        return $this->setting;
    }

    /**
     * Uji koneksi ke NBI dengan permintaan ringan (1 device, hanya _id).
     *
     * @return array{ok: bool, latency_ms: int, message: string, http_status: int|null}
     */
    public function testConnection(): array
    {
        $started = hrtime(true);

        try {
            $response = $this->get('/devices/', ['projection' => '_id', 'limit' => 1]);
            $latency = (int) ((hrtime(true) - $started) / 1e6);

            if (! is_array($response->json())) {
                return $this->testResult(false, $latency, 'Server merespons, tetapi bukan GenieACS NBI (respons bukan JSON).', $response->status());
            }

            return $this->testResult(true, $latency, "Terhubung ke GenieACS NBI ({$latency} ms).", $response->status());
        } catch (GenieACSException $e) {
            $latency = (int) ((hrtime(true) - $started) / 1e6);

            return $this->testResult(false, $latency, $e->getMessage(), null);
        }
    }

    /** Field yang cukup untuk identifikasi perangkat (tanpa seluruh pohon parameter). */
    public const IDENTITY_PROJECTION = '_id,_deviceId,_lastInform,_registered,_tags';

    /**
     * Cari device berdasarkan _deviceId._SerialNumber. Hasil SELALU berupa daftar
     * (bisa 0, 1, atau lebih) dan setiap hasil divalidasi ulang agar SN-nya cocok.
     *
     * @return list<ParameterTree>
     */
    public function findDeviceBySerialNumber(string $serialNumber): array
    {
        $variants = SerialNumber::variants($serialNumber);
        $query = count($variants) === 1
            ? ['_deviceId._SerialNumber' => $variants[0]]
            : ['_deviceId._SerialNumber' => ['$in' => $variants]];

        $response = $this->get('/devices/', [
            'query' => json_encode($query, JSON_UNESCAPED_SLASHES),
            'projection' => self::IDENTITY_PROJECTION,
            'limit' => 20,
        ]);

        $devices = [];
        foreach ((array) $response->json() as $doc) {
            if (! is_array($doc)) {
                continue;
            }
            $tree = ParameterTree::fromDocument($doc);
            $sn = $tree->deviceId()['serial_number'];
            // Validasi: jangan percaya hasil query mentah-mentah.
            if ($sn !== null && SerialNumber::equals($serialNumber, $sn)) {
                $devices[] = $tree;
            }
        }

        return $devices;
    }

    /** Dokumen lengkap device (seluruh parameter yang sudah diketahui GenieACS). */
    public function getDeviceDetails(string $deviceId): ParameterTree
    {
        $response = $this->get('/devices/', [
            'query' => json_encode(['_id' => $deviceId], JSON_UNESCAPED_SLASHES),
        ]);

        $docs = (array) $response->json();
        if (count($docs) !== 1 || ! is_array($docs[0]) || ($docs[0]['_id'] ?? null) !== $deviceId) {
            throw new DeviceNotFoundException;
        }

        return ParameterTree::fromDocument($docs[0]);
    }

    /**
     * @return list<array{path: string, value: mixed, type: ?string, writable: ?bool, timestamp: ?string, secret: bool}>
     */
    public function getDeviceParameters(string $deviceId, ?string $prefix = null): array
    {
        return $this->getDeviceDetails($deviceId)->flatten($prefix);
    }

    public function getDeviceLastInform(string $deviceId): ?CarbonImmutable
    {
        $response = $this->get('/devices/', [
            'query' => json_encode(['_id' => $deviceId], JSON_UNESCAPED_SLASHES),
            'projection' => '_id,_lastInform',
        ]);

        $doc = ((array) $response->json())[0] ?? null;
        if (! is_array($doc)) {
            throw new DeviceNotFoundException;
        }

        return ParameterTree::fromDocument($doc)->lastInform();
    }

    /**
     * Cek online SEKARANG dengan Connection Request + getParameterValues ringan.
     * HTTP 200 = ONT merespons & task selesai. HTTP 202 = ONT tidak merespons
     * (task dibatalkan agar tidak menumpuk di antrean).
     *
     * @return array{online: bool, message: string, checked_at: string, uptime: mixed}
     */
    public function getDeviceConnectionStatus(string $deviceId, string $root = 'InternetGatewayDevice'): array
    {
        $result = $this->createTask($deviceId, [
            'name' => 'getParameterValues',
            'parameterNames' => [$root.'.DeviceInfo.UpTime'],
        ]);

        $now = now()->toIso8601String();

        if ($result['completed']) {
            $uptime = null;
            try {
                $uptime = $this->getDeviceDetails($deviceId)->value($root.'.DeviceInfo.UpTime');
            } catch (GenieACSException) {
                // Nilai uptime hanya pelengkap.
            }

            return ['online' => true, 'message' => 'ONT merespons Connection Request.', 'checked_at' => $now, 'uptime' => $uptime];
        }

        if ($result['task_id']) {
            $this->deleteTask($result['task_id']);
        }

        return [
            'online' => false,
            'message' => 'ONT tidak merespons Connection Request ('.$result['reason'].').',
            'checked_at' => $now,
            'uptime' => null,
        ];
    }

    /**
     * Minta ONT mengirim ulang nilai objek (refreshObject). Default: seluruh root.
     *
     * @return array{completed: bool, task_id: ?string, message: string}
     */
    public function refreshDeviceParameters(string $deviceId, string $objectName, ?int $timeoutSeconds = null): array
    {
        $result = $this->createTask($deviceId, ['name' => 'refreshObject', 'objectName' => $objectName], true, $timeoutSeconds);

        if ($result['completed']) {
            return ['completed' => true, 'task_id' => $result['task_id'], 'message' => "Parameter {$objectName} berhasil dibaca ulang dari ONT."];
        }

        return [
            'completed' => false,
            'task_id' => $result['task_id'],
            'message' => "Refresh belum selesai ({$result['reason']}). Task tetap di antrean GenieACS dan dijalankan saat ONT inform berikutnya.",
        ];
    }

    /**
     * Kirim task ke GenieACS. "completed" = TRUE hanya bila NBI menjawab 200
     * (task benar-benar dieksekusi ONT dalam batas waktu).
     *
     * @param  array<string, mixed>  $task
     * @return array{completed: bool, status: int, task_id: ?string, reason: string}
     */
    public function createTask(string $deviceId, array $task, bool $connectionRequest = true, ?int $timeoutSeconds = null): array
    {
        $timeoutSeconds ??= (int) $this->setting->connection_request_timeout;
        $query = $connectionRequest
            ? ['connection_request' => '', 'timeout' => max(1, $timeoutSeconds) * 1000]
            : [];

        // NBI menahan respons sampai task selesai / timeout, jadi timeout HTTP harus lebih panjang.
        $this->requestTimeoutOverride = $connectionRequest
            ? max((int) $this->setting->request_timeout, $timeoutSeconds + 10)
            : null;

        try {
            $response = $this->post('/devices/'.rawurlencode($deviceId).'/tasks', $task, $query);
        } finally {
            $this->requestTimeoutOverride = null;
        }

        if ($response->status() === 404) {
            throw new DeviceNotFoundException;
        }

        $body = $response->json();

        return [
            'completed' => $response->status() === 200,
            'status' => $response->status(),
            'task_id' => is_array($body) ? ($body['_id'] ?? null) : null,
            'reason' => $response->status() === 200 ? 'OK' : ($response->reason() ?: 'Task queued'),
        ];
    }

    /**
     * Status task: "done" bila task sudah hilang dari antrean, "fault" bila ada fault,
     * selain itu "pending".
     *
     * @return array{state: string, fault: ?array{code: ?string, message: ?string}}
     */
    public function getTaskStatus(string $taskId, string $deviceId): array
    {
        $tasks = (array) $this->get('/tasks/', ['query' => json_encode(['_id' => $taskId])])->json();
        if ($tasks === []) {
            return ['state' => 'done', 'fault' => null];
        }

        $faults = (array) $this->get('/faults/', ['query' => json_encode(['_id' => $deviceId.':task_'.$taskId])])->json();
        if ($faults !== [] && is_array($faults[0])) {
            return ['state' => 'fault', 'fault' => [
                'code' => $faults[0]['code'] ?? null,
                'message' => $faults[0]['message'] ?? ($faults[0]['detail']['faultString'] ?? null),
            ]];
        }

        return ['state' => 'pending', 'fault' => null];
    }

    /**
     * Kirim task lalu TUNGGU sampai benar-benar selesai di ONT. Request NBI yang diterima
     * (HTTP 202) belum berarti diterapkan; status dipantau sampai task hilang dari antrean,
     * terjadi fault, atau batas waktu habis (task lalu dihapus agar tidak jalan diam-diam nanti).
     *
     * @param  array<string, mixed>  $task
     * @return array{state: string, task_id: ?string, fault: ?array}
     */
    public function createProvisioningTask(string $deviceId, array $task, ?int $timeoutSeconds = null): array
    {
        $timeoutSeconds ??= (int) config('ontconfig.task_timeout_seconds');
        $created = $this->createTask($deviceId, $task);

        if ($created['completed']) {
            return ['state' => 'done', 'task_id' => $created['task_id'], 'fault' => null];
        }
        if ($created['task_id'] === null) {
            throw new GenieACSException('GenieACS tidak mengembalikan ID task.', 'TASK_CREATE_FAILED');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $interval = max(0, (int) config('ontconfig.task_poll_interval_ms'));
        do {
            if ($interval > 0) {
                usleep($interval * 1000);
            }
            $status = $this->getTaskStatus($created['task_id'], $deviceId);
            if ($status['state'] === 'done') {
                return ['state' => 'done', 'task_id' => $created['task_id'], 'fault' => null];
            }
            if ($status['state'] === 'fault') {
                $this->deleteTask($created['task_id']);

                return ['state' => 'fault', 'task_id' => $created['task_id'], 'fault' => $status['fault']];
            }
        } while (microtime(true) < $deadline);

        $this->deleteTask($created['task_id']);

        return ['state' => 'timeout', 'task_id' => $created['task_id'], 'fault' => null];
    }

    /** @param list<array{0: string, 1: mixed, 2: string}> $values */
    public function setParameterValues(string $deviceId, array $values, ?int $timeoutSeconds = null): array
    {
        return $this->createProvisioningTask($deviceId, ['name' => 'setParameterValues', 'parameterValues' => $values], $timeoutSeconds);
    }

    public function addObject(string $deviceId, string $objectName, ?int $timeoutSeconds = null): array
    {
        return $this->createProvisioningTask($deviceId, ['name' => 'addObject', 'objectName' => $objectName], $timeoutSeconds);
    }

    public function deleteObject(string $deviceId, string $objectName, ?int $timeoutSeconds = null): array
    {
        return $this->createProvisioningTask($deviceId, ['name' => 'deleteObject', 'objectName' => $objectName], $timeoutSeconds);
    }

    public function deleteTask(string $taskId): void
    {
        try {
            $this->delete('/tasks/'.rawurlencode($taskId));
        } catch (GenieACSException $e) {
            Log::warning('Gagal menghapus task GenieACS', ['task' => $taskId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Daftar device (untuk sinkronisasi cache lokal), dengan paging.
     *
     * @return list<ParameterTree>
     */
    public function listDevices(int $limit = 500, int $skip = 0, ?string $projection = null): array
    {
        $response = $this->get('/devices/', [
            'projection' => $projection ?? self::IDENTITY_PROJECTION
                .',InternetGatewayDevice.DeviceInfo.HardwareVersion,InternetGatewayDevice.DeviceInfo.SoftwareVersion'
                .',Device.DeviceInfo.HardwareVersion,Device.DeviceInfo.SoftwareVersion',
            'limit' => $limit,
            'skip' => $skip,
            'sort' => json_encode(['_id' => 1]),
        ]);

        return array_values(array_map(
            fn (array $doc) => ParameterTree::fromDocument($doc),
            array_filter((array) $response->json(), 'is_array'),
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload = [], array $query = []): Response
    {
        return $this->send('post', $path, $payload, $query);
    }

    public function delete(string $path): Response
    {
        return $this->send('delete', $path);
    }

    private function send(string $method, string $path, array $data = [], array $query = []): Response
    {
        // Untuk GET, $data adalah query string.
        if ($method === 'get') {
            [$query, $data] = [$data, []];
        }

        $url = rtrim($this->setting->nbi_url, '/').'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        try {
            $response = $method === 'post'
                ? $this->client()->asJson()->post($url, $data)
                : $this->client()->{$method}($url);
        } catch (ConnectionException $e) {
            Log::warning('GenieACS tidak dapat diakses', ['method' => strtoupper($method), 'path' => $path, 'error' => $e->getMessage()]);
            throw new GenieACSUnreachableException(previous: $e);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new GenieACSAuthException;
        }

        if ($response->serverError()) {
            Log::warning('GenieACS server error', ['path' => $path, 'status' => $response->status()]);
            throw new GenieACSException("GenieACS mengembalikan error server (HTTP {$response->status()}).", 'GENIEACS_SERVER_ERROR');
        }

        if ($response->clientError() && $response->status() !== 404) {
            throw new GenieACSException("Permintaan ditolak GenieACS (HTTP {$response->status()}).", 'GENIEACS_REQUEST_REJECTED');
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout(max(1, (int) $this->setting->connect_timeout))
            ->timeout(max(1, $this->requestTimeoutOverride ?? (int) $this->setting->request_timeout))
            ->withOptions(['verify' => (bool) $this->setting->verify_tls])
            ->withUserAgent('ONT-AutoConfig/1.0');

        if (filled($this->setting->username)) {
            $request = $request->withBasicAuth((string) $this->setting->username, (string) $this->setting->password);
        }

        return $request;
    }

    private function testResult(bool $ok, int $latency, string $message, ?int $status): array
    {
        return ['ok' => $ok, 'latency_ms' => $latency, 'message' => $message, 'http_status' => $status];
    }
}

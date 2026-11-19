<?php

namespace App\Services\Pppoe;

use App\Models\GenieAcsSetting;
use App\Models\Router;
use App\Services\GenieACS\GenieACSService;
use App\Services\GenieACS\ParameterTree;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Support\SerialNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Status online langsung dari sumbernya (di-cache sebentar agar halaman tetap cepat):
 *  - PPPoE: /ppp/active tiap MikroTik (sesi dari secret lokal tidak tercatat di radacct).
 *  - ONT  : _lastInform di GenieACS, dicocokkan dengan bentuk SN apa pun (Huawei HWTC… = 48575443…).
 */
class CustomerLiveStatus
{
    private const TTL_SECONDS = 30;

    public function __construct(private readonly MikrotikConnector $mikrotik) {}

    /** @return array<string, object{username: string, framedipaddress: ?string, acctstarttime: ?string}> username => sesi */
    public function pppoeSessions(): array
    {
        // Cache hanya menyimpan array biasa: cache.serializable_classes = false menolak objek saat dibaca ulang.
        $rows = Cache::remember('snet.live.ppp_active', self::TTL_SECONDS, function () {
            $sessions = [];
            foreach (Router::where('status', 'active')->get() as $router) {
                $active = rescue(fn () => $this->mikrotik->with($router, fn (MikrotikConnection $api) => $api->comm('/ppp/active/print')), [], report: false);
                foreach ((array) $active as $row) {
                    if (filled($row['name'] ?? null)) {
                        $sessions[$row['name']] = [
                            'username' => $row['name'],
                            'framedipaddress' => $row['address'] ?? null,
                            'acctstarttime' => self::startFromUptime($row['uptime'] ?? null),
                        ];
                    }
                }
            }

            return $sessions;
        });

        return array_map(fn (array $row) => (object) $row, $rows);
    }

    /**
     * @param  list<string>  $serials  SN ONT pelanggan
     * @return array<string, CarbonImmutable> SN pelanggan => inform terakhir
     */
    public function ontLastInform(array $serials): array
    {
        $serials = array_values(array_unique(array_filter(array_map([SerialNumber::class, 'normalize'], $serials))));
        if ($serials === []) {
            return [];
        }

        $found = Cache::remember('snet.live.ont_inform.'.md5(implode(',', $serials)), self::TTL_SECONDS, function () use ($serials) {
            $variants = array_merge(...array_map([SerialNumber::class, 'variants'], $serials));
            $out = [];
            foreach (GenieAcsSetting::where('is_active', true)->get() as $setting) {
                $docs = rescue(fn () => (array) (new GenieACSService($setting))->get('/devices/', [
                    'query' => json_encode(['_deviceId._SerialNumber' => ['$in' => $variants]], JSON_UNESCAPED_SLASHES),
                    'projection' => '_deviceId,_lastInform',
                    'limit' => count($variants),
                ])->json(), [], report: false);
                foreach (array_filter($docs, 'is_array') as $doc) {
                    $tree = ParameterTree::fromDocument($doc);
                    $sn = (string) $tree->deviceId()['serial_number'];
                    $at = isset($doc['_lastInform']) ? CarbonImmutable::parse($doc['_lastInform'])->getTimestamp() : null;
                    if ($sn !== '' && $at) {
                        $out[$sn] = max($out[$sn] ?? 0, $at);
                    }
                }
            }

            return $out;
        });

        $result = [];
        foreach ($serials as $serial) {
            foreach ($found as $acsSn => $at) {
                if (SerialNumber::equals($serial, (string) $acsSn)) {
                    $result[$serial] = CarbonImmutable::createFromTimestamp($at);
                }
            }
        }

        return $result;
    }

    /** Uptime MikroTik (mis. "1w2d3h4m5s") → waktu mulai sesi. */
    public static function startFromUptime(?string $uptime): ?string
    {
        if (! $uptime || ! preg_match_all('/(\d+)([wdhms])/', $uptime, $m, PREG_SET_ORDER)) {
            return null;
        }
        $seconds = 0;
        foreach ($m as [, $n, $unit]) {
            $seconds += (int) $n * ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1][$unit];
        }

        return now()->subSeconds($seconds)->toDateTimeString();
    }
}

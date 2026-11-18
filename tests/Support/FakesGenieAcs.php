<?php

namespace Tests\Support;

use App\Models\GenieAcsSetting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait FakesGenieAcs
{
    protected const NBI = 'http://acs.test:7557';

    protected function useFakeAcsSetting(): GenieAcsSetting
    {
        return GenieAcsSetting::create([
            'nbi_url' => self::NBI, 'connect_timeout' => 2, 'request_timeout' => 10,
            'connection_request_timeout' => 3, 'verify_tls' => true, 'is_active' => true,
        ]);
    }

    /** Dokumen device asli FiberHome HG6145D2 (secret sudah disamarkan). */
    protected function fiberhomeDoc(array $override = []): array
    {
        $doc = json_decode(file_get_contents(base_path('tests/Fixtures/genieacs/fiberhome_hg6145d2.json')), true)[0];

        return array_replace_recursive($doc, $override);
    }

    protected function identity(array $doc): array
    {
        return array_intersect_key($doc, array_flip(['_id', '_deviceId', '_lastInform', '_registered']));
    }

    /**
     * @param  array<int, array>  $devices  dokumen lengkap
     * @param  int  $taskStatus  200 = ONT merespons, 202 = tidak merespons
     */
    protected function fakeAcs(array $devices, int $taskStatus = 200): void
    {
        Http::fake(function (Request $request) use ($devices, $taskStatus) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            if ($request->method() === 'GET' && $path === '/devices/') {
                $query = isset($q['query']) ? json_decode($q['query'], true) : [];
                $matched = array_values(array_filter($devices, function ($d) use ($query) {
                    if (isset($query['_id'])) {
                        return $d['_id'] === $query['_id'];
                    }
                    if (isset($query['_deviceId._SerialNumber'])) {
                        $sn = $query['_deviceId._SerialNumber'];
                        $list = is_array($sn) ? $sn['$in'] : [$sn];

                        return in_array($d['_deviceId']['_SerialNumber'], $list, true);
                    }

                    return true;
                }));
                if (($q['projection'] ?? null) && str_starts_with($q['projection'], '_id,_deviceId')) {
                    $matched = array_map(fn ($d) => $this->identity($d), $matched);
                }
                $skip = (int) ($q['skip'] ?? 0);
                $limit = (int) ($q['limit'] ?? 1000);

                return Http::response(array_slice($matched, $skip, $limit), 200);
            }

            if ($request->method() === 'POST' && preg_match('#^/devices/[^/]+/tasks$#', $path)) {
                return Http::response(['_id' => 'task-'.md5($url.json_encode($request->data()))] + $request->data(), $taskStatus,
                    $taskStatus === 202 ? [] : []);
            }

            if ($request->method() === 'DELETE' && str_starts_with($path, '/tasks/')) {
                return Http::response('', 200);
            }

            return Http::response(['message' => 'not faked: '.$request->method().' '.$url], 500);
        });
    }
}

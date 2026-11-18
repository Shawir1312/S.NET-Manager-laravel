<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API AJAX halaman Daftar MAC (port dari ajax/api_mac.php): IP-Binding bypass hotspot
 * + Simple Queue limit per perangkat (IP dicari dari DHCP lease → hotspot host → ARP).
 */
class MacApiController extends Controller
{
    private const POST_ACTIONS = ['add', 'update', 'delete', 'toggle_status', 'sync_all', 'sync_one'];

    public function __construct(private readonly MikrotikConnector $mikrotik) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('operate');
        $action = (string) $request->input('action', 'list');
        abort_if(in_array($action, self::POST_ACTIONS, true) && ! $request->isMethod('post'), 405);
        $router = Router::findOrFail($request->integer('router_id'));
        $limit = $this->limit((string) $request->input('limit', '2M/2M'));

        try {
            return $this->mikrotik->with($router, fn (MikrotikConnection $api) => match ($action) {
                'list' => response()->json(['success' => true, 'data' => $this->list($api)]),
                'add' => $this->add($api, $request, $limit),
                'update' => $this->update($api, $request, $limit),
                'delete' => $this->delete($api, $this->id($request)),
                'toggle_status' => $this->toggle($api, $this->id($request), $request->input('status') === 'enable'),
                'sync_all' => $this->syncAll($api, $limit),
                'sync_one' => $this->syncOne($api, $this->id($request), $limit),
                default => $this->fail('Aksi tidak dikenal.'),
            });
        } catch (MikrotikException $e) {
            return $this->fail('Gagal terhubung ke router: '.$e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function list(MikrotikConnection $api): array
    {
        $byMac = fn (array $rows) => collect($rows)->filter(fn ($r) => self::norm($r['mac-address'] ?? '') !== '')->keyBy(fn ($r) => self::norm($r['mac-address']));
        $leases = $byMac($api->comm('/ip/dhcp-server/lease/print'));
        $hosts = $byMac($api->comm('/ip/hotspot/host/print'));
        $arps = $byMac(array_filter($api->comm('/ip/arp/print'), fn ($a) => ($a['complete'] ?? 'true') !== 'false' && ($a['disabled'] ?? 'false') === 'false'));
        $queues = [];
        foreach ($api->comm('/queue/simple/print') as $q) {
            $queues[explode('/', $q['target'] ?? '')[0]] = $q['max-limit'] ?? '';
            $queues['name:'.($q['name'] ?? '')] = $q['max-limit'] ?? '';
        }

        return array_map(function (array $b) use ($leases, $hosts, $arps, $queues) {
            $norm = self::norm($b['mac-address'] ?? '');
            $l = $leases[$norm] ?? null;
            $h = $hosts[$norm] ?? null;
            $arp = $arps[$norm] ?? null;
            $ip = $h['address'] ?? $arp['address'] ?? $l['active-address'] ?? $l['address'] ?? $b['address'] ?? '';
            $in = (int) ($h['bytes-in'] ?? 0);
            $out = (int) ($h['bytes-out'] ?? 0);
            $limit = $queues[$ip] ?? $queues['name:'.($b['comment'] ?? '')] ?? '';

            return [
                'id' => $b['.id'] ?? '', 'mac' => strtoupper(trim($b['mac-address'] ?? '')), 'type' => $b['type'] ?? '',
                'comment' => $b['comment'] ?? '', 'disabled' => ($b['disabled'] ?? 'false') === 'true',
                'is_static' => $l !== null && ($l['dynamic'] ?? 'false') === 'false', 'is_online' => $h !== null || $arp !== null,
                'ip_address' => $ip, 'uptime' => $h['uptime'] ?? '', 'host_name' => $l['host-name'] ?? '',
                'traffic' => $in || $out ? '↓ '.format_bytes($in).' · ↑ '.format_bytes($out) : '',
                'limit' => $limit ?: '2M/2M', 'has_queue' => $limit !== '',
            ];
        }, $api->comm('/ip/hotspot/ip-binding/print'));
    }

    private function add(MikrotikConnection $api, Request $request, ?string $limit): JsonResponse
    {
        [$mac, $name] = $this->macAndName($request);
        foreach ($api->comm('/ip/hotspot/ip-binding/print') as $b) {
            if (self::norm($b['mac-address'] ?? '') === self::norm($mac)) {
                return $this->fail("MAC {$mac} sudah terdaftar sebelumnya di MikroTik (".($b['comment'] ?? 'Bypass').')');
            }
        }
        $api->comm('/ip/hotspot/ip-binding/add', ['mac-address' => $mac, 'type' => 'bypassed', 'comment' => $name]);
        $synced = $limit === null || $this->syncQueue($api, $mac, $name, $limit);

        return $this->ok($synced ? "MAC {$mac} berhasil didaftarkan." : "MAC {$mac} didaftarkan. Limit akan diterapkan setelah perangkat mendapat IP (klik ⚡ Limit).");
    }

    private function update(MikrotikConnection $api, Request $request, ?string $limit): JsonResponse
    {
        [$mac, $name] = $this->macAndName($request);
        $api->comm('/ip/hotspot/ip-binding/set', ['.id' => $this->id($request), 'mac-address' => $mac, 'comment' => $name]);
        if ($limit !== null) {
            $this->syncQueue($api, $mac, $name, $limit);
        } else {
            $this->removeQueues($api, $mac, $name);
        }

        return $this->ok('Data berhasil diubah.');
    }

    private function delete(MikrotikConnection $api, string $id): JsonResponse
    {
        $b = $api->comm('/ip/hotspot/ip-binding/print', ['?.id' => $id])[0] ?? null;
        if ($b) {
            $this->removeQueues($api, $b['mac-address'] ?? '', $b['comment'] ?? '');
        }
        $api->comm('/ip/hotspot/ip-binding/remove', ['.id' => $id]);

        return $this->ok('MAC berhasil dihapus.');
    }

    private function toggle(MikrotikConnection $api, string $id, bool $enable): JsonResponse
    {
        $api->comm('/ip/hotspot/ip-binding/'.($enable ? 'enable' : 'disable'), ['.id' => $id]);

        return $this->ok($enable ? 'Rule MAC diaktifkan.' : 'Rule MAC dinonaktifkan.');
    }

    private function syncAll(MikrotikConnection $api, ?string $limit): JsonResponse
    {
        $ok = $skip = 0;
        foreach ($api->comm('/ip/hotspot/ip-binding/print', ['?type' => 'bypassed']) as $b) {
            $this->syncQueue($api, $b['mac-address'] ?? '', $b['comment'] ?? 'Bypass MAC', $limit ?? '2M/2M', $b['address'] ?? '') ? $ok++ : $skip++;
        }

        return $this->ok("{$ok} MAC disinkronkan".($skip ? ", {$skip} dilewati (belum punya IP)." : '.'));
    }

    private function syncOne(MikrotikConnection $api, string $id, ?string $limit): JsonResponse
    {
        $b = $api->comm('/ip/hotspot/ip-binding/print', ['?.id' => $id])[0] ?? null;
        if (! $b) {
            return $this->fail('Binding tidak ditemukan.');
        }

        return $this->syncQueue($api, $b['mac-address'] ?? '', $b['comment'] ?? 'Bypass MAC', $limit ?? '2M/2M', $b['address'] ?? '')
            ? $this->ok('Limit & IP statik berhasil disinkronkan.')
            : $this->fail('Perangkat belum punya IP (belum aktif di jaringan).');
    }

    /** Cari IP perangkat, jadikan lease statik, lalu buat/ubah Simple Queue. */
    private function syncQueue(MikrotikConnection $api, string $mac, string $name, string $limit, string $bindingIp = ''): bool
    {
        $norm = self::norm($mac);
        $lease = collect($api->comm('/ip/dhcp-server/lease/print'))->filter(fn ($l) => self::norm($l['mac-address'] ?? '') === $norm)
            ->sortByDesc(fn ($l) => ($l['status'] ?? '') === 'bound')->first();
        $address = $lease['active-address'] ?? $lease['address'] ?? null;
        foreach (['/ip/hotspot/host/print', '/ip/arp/print'] as $path) {
            $address ??= collect($api->comm($path))->first(fn ($r) => self::norm($r['mac-address'] ?? '') === $norm && ! empty($r['address']))['address'] ?? null;
        }
        $address = explode('/', (string) ($address ?: $bindingIp))[0];
        if ($address === '') {
            return false;
        }
        if ($lease && ($lease['dynamic'] ?? 'false') === 'true') {
            rescue(fn () => $api->comm('/ip/dhcp-server/lease/make-static', ['.id' => $lease['.id']]), report: false);
        }
        $params = ['name' => $name, 'target' => $address.'/32', 'max-limit' => $limit, 'comment' => 'Bypass MAC - '.$mac];
        $queue = collect($api->comm('/queue/simple/print'))->first(fn ($q) => explode('/', $q['target'] ?? '')[0] === $address || ($q['name'] ?? '') === $name);
        $api->comm($queue ? '/queue/simple/set' : '/queue/simple/add', $queue ? ['.id' => $queue['.id']] + $params : $params);

        return true;
    }

    private function removeQueues(MikrotikConnection $api, string $mac, string $name): void
    {
        foreach ($api->comm('/queue/simple/print') as $q) {
            if (($q['comment'] ?? '') === 'Bypass MAC - '.$mac || ($name !== '' && ($q['name'] ?? '') === $name)) {
                $api->comm('/queue/simple/remove', ['.id' => $q['.id']]);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function macAndName(Request $request): array
    {
        $d = $request->validate([
            'mac' => ['required', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        return [strtoupper(str_replace('-', ':', $d['mac'])), $d['name']];
    }

    private function id(Request $request): string
    {
        return $request->validate(['id' => ['required', 'regex:/^\*[0-9A-Fa-f]+$/']])['id'];
    }

    /** null = unlimited (tanpa queue). */
    private function limit(string $limit): ?string
    {
        return $limit === 'unlimited' ? null : (preg_match('/^[0-9]+[kKmMgG]?\/[0-9]+[kKmMgG]?$/', $limit) ? $limit : '2M/2M');
    }

    private static function norm(string $mac): string
    {
        return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    }

    private function ok(string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message]);
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message]);
    }
}

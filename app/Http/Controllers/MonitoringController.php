<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Hotspot\VoucherService;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;
use Carbon\CarbonInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Sesi aktif (radacct) dan daftar MAC bypass/blokir (IP-Binding hotspot). */
class MonitoringController extends Controller
{
    public function __construct(private readonly MikrotikConnector $mikrotik) {}

    public function active(Request $request): View|JsonResponse
    {
        Gate::authorize('operate');
        $routers = Router::orderBy('name')->get();
        if (! $request->wantsJson() && $request->query('format') !== 'json') {
            return view('monitoring.active', ['all_routers' => $routers, 'filter_router' => $request->integer('router_id')]);
        }
        $router = $routers->firstWhere('id', $request->integer('router_id'));
        $rows = DB::table('radacct as ra')
            ->leftJoin('vouchers as v', 'v.username', '=', 'ra.username')
            ->leftJoin('profiles as p', 'p.id', '=', 'v.profile_id')
            ->leftJoin('pppoe_customers as c', 'c.pppoe_username', '=', 'ra.username')
            ->whereNull('ra.acctstoptime')
            ->when($router, fn ($q) => $q->whereIn('ra.nasipaddress', $this->nasIps($router)))
            ->orderByDesc('ra.acctstarttime')->limit(1000)
            ->get(['ra.radacctid', 'ra.username', 'ra.framedipaddress', 'ra.callingstationid', 'ra.nasipaddress', 'ra.acctstarttime',
                'ra.acctinputoctets', 'ra.acctoutputoctets', 'v.expired_at', 'p.name as profile_name', 'p.duration_value', 'p.duration_unit', 'c.profile as pppoe_profile']);
        $routerByIp = [];
        foreach ($routers as $r) {
            foreach ($this->nasIps($r) as $ip) {
                $routerByIp[$ip] = $r->name;
            }
        }
        $used = DB::table('radacct')->whereIn('username', $rows->pluck('username')->filter(fn ($u, $i) => $rows[$i]->duration_value > 0))
            ->groupBy('username')->selectRaw('username, sum(acctsessiontime) as used')->pluck('used', 'username');
        $human = fn (int $sec) => CarbonInterval::seconds($sec)->cascade()->forHumans(short: true, parts: 2);

        $users = $rows->map(function ($row) use ($routerByIp, $used, $human) {
            $sisa = '';
            if ($row->duration_value > 0) {
                $rem = VoucherService::seconds((int) $row->duration_value, $row->duration_unit) - (int) ($used[$row->username] ?? 0);
                $sisa .= '<div style="font-size:0.7rem;margin-bottom:2px;" title="Sisa Kuota Waktu (Durasi)"><i class="bi bi-hourglass-split"></i> '.($rem <= 0 ? 'Habis' : $human($rem)).'</div>';
            }
            if ($row->expired_at) {
                $rem = Carbon::parse($row->expired_at)->getTimestamp() - time();
                $sisa .= '<div style="font-size:0.7rem;color:var(--red);" title="Sisa Masa Aktif (Validity)"><i class="bi bi-calendar-x"></i> '.($rem <= 0 ? 'Habis' : $human($rem)).'</div>';
            }

            return [
                'radacctid' => (string) $row->radacctid, 'username' => $row->username, 'nasipaddress' => $row->nasipaddress,
                'router_name' => $routerByIp[$row->nasipaddress] ?? $row->nasipaddress, 'callingstationid' => $row->callingstationid,
                'framedipaddress' => $row->framedipaddress, 'duration' => $human(max(0, time() - Carbon::parse($row->acctstarttime)->getTimestamp())),
                'sisa_waktu' => $sisa ?: '<span style="font-size:0.75rem;">Unlimited</span>',
                'dl' => format_bytes((int) $row->acctoutputoctets), 'ul' => format_bytes((int) $row->acctinputoctets),
                'profile' => $row->profile_name ?? $row->pppoe_profile,
            ];
        });

        return response()->json(['count' => $users->count(), 'users' => $users->values()]);
    }

    /**
     * sync_mikrotik: tutup sesi radacct yang tidak lagi aktif di router (hotspot & PPP).
     * clear_offline_ghosts: tutup semua sesi terbuka milik router yang tidak bisa dihubungi.
     */
    public function activeAction(Request $request): JsonResponse
    {
        Gate::authorize('operate');
        $action = $request->validate(['action' => ['required', 'in:sync_mikrotik,clear_offline_ghosts']])['action'];
        $routers = Router::where('status', 'active')->when($request->integer('router_id'), fn ($q, $id) => $q->whereKey($id))->get();
        $active = $closed = 0;

        foreach ($routers as $router) {
            $open = DB::table('radacct')->whereNull('acctstoptime')->whereIn('nasipaddress', $this->nasIps($router));
            try {
                $names = $this->mikrotik->with($router, fn (MikrotikConnection $api) => array_merge(
                    array_column($api->comm('/ip/hotspot/active/print'), 'user'),
                    array_column($api->comm('/ppp/active/print'), 'name'),
                ));
            } catch (MikrotikException) {
                if ($action === 'clear_offline_ghosts') {
                    $closed += $open->update(['acctstoptime' => now(), 'acctterminatecause' => 'NAS-Reboot']);
                }

                continue;
            }
            if ($action === 'sync_mikrotik') {
                $active += count($names);
                $closed += (clone $open)->whereNotIn('username', $names)->update(['acctstoptime' => now(), 'acctterminatecause' => 'Lost-Carrier']);
            }
        }

        return response()->json(['success' => true, 'active_count' => $active, 'restored' => 0, 'created' => 0, 'closed' => $closed, 'closed_count' => $closed]);
    }

    /** @return list<string> */
    private function nasIps(Router $router): array
    {
        return array_values(array_filter([$router->ip_address, explode('/', (string) $router->nas_ip)[0]]));
    }

    /** Putus sesi: kick dari MikroTik (hotspot & PPP) lalu tutup baris radacct. */
    public function disconnect(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('operate');
        $id = $request->validate(['radacctid' => ['required', 'integer']])['radacctid'];
        $s = DB::table('radacct')->where('radacctid', $id)->firstOrFail();
        $router = Router::where('ip_address', $s->nasipaddress)->orWhere('nas_ip', 'like', $s->nasipaddress.'%')->first();

        if ($router) {
            rescue(fn () => $this->mikrotik->with($router, function (MikrotikConnection $api) use ($s) {
                foreach ($api->comm('/ip/hotspot/active/print', ['?user' => $s->username]) as $a) {
                    $api->comm('/ip/hotspot/active/remove', ['.id' => $a['.id']]);
                }
                $api->kickActive($s->username);
            }), report: false);
        }
        DB::table('radacct')->where('radacctid', $id)->update(['acctstoptime' => now(), 'acctterminatecause' => 'Admin-Reset']);

        return $request->wantsJson() ? response()->json(['success' => true]) : back()->with('status', "Sesi {$s->username} diputus.");
    }

    public function mac(Request $request): View
    {
        Gate::authorize('operate');

        return view('monitoring.mac', ['all_routers' => Router::orderBy('name')->get(['id', 'name', 'ip_address']), 'router_id' => $request->integer('router_id')]);
    }
}

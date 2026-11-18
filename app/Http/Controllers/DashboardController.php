<?php

namespace App\Http\Controllers;

use App\Models\OntDevice;
use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Models\Router;
use App\Models\SalesLog;
use App\Models\Voucher;
use App\Models\WgPortForward;
use App\Models\WgRouter;
use App\Services\WireGuard\WireGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Dashboard S.NET: ringkasan Hotspot, Broadband PPPoE, WireGuard (+ ONT dari GenieACS). */
class DashboardController extends Controller
{
    public function __invoke(WireGuardService $wireguard): View
    {
        $today = CarbonImmutable::today();
        $month = $today->startOfMonth();
        $routers = Router::orderBy('name')->get();

        $activeByNas = DB::table('radacct')->whereNull('acctstoptime')
            ->selectRaw('nasipaddress, count(distinct username) as cnt')->groupBy('nasipaddress')->pluck('cnt', 'nasipaddress');

        $sales = fn ($from) => SalesLog::where('sold_at', '>=', $from)->selectRaw('count(*) as cnt, coalesce(sum(price), 0) as total')->first();
        $peerStatus = $wireguard->peerStatus();
        $wgKeys = WgRouter::pluck('public_key');

        return view('dashboard', [
            'routers' => $routers,
            'activeByRouter' => $routers->mapWithKeys(fn (Router $r) => [$r->id => (int) ($activeByNas[$r->ip_address] ?? 0) + (int) ($activeByNas[explode('/', $r->nas_ip)[0]] ?? 0)]),
            'activeSessions' => DB::table('radacct')->whereNull('acctstoptime')->distinct()->count('username'),
            'vouchers' => Voucher::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'todaySales' => $sales($today),
            'monthSales' => $sales($month),
            'routerSales' => $routers->map(fn (Router $r) => [
                'name' => $r->name,
                'today' => (float) SalesLog::where('router_id', $r->id)->where('sold_at', '>=', $today)->sum('price'),
                'month' => (float) SalesLog::where('router_id', $r->id)->where('sold_at', '>=', $month)->sum('price'),
            ]),
            'recentBatches' => Voucher::whereNotNull('batch_id')->where('status', '!=', 'deleted')
                ->selectRaw("batch_id, max(created_at) as created_at, max(profile_id) as profile_id, max(router_id) as router_id, count(*) as qty, sum(case when status = 'unused' then 1 else 0 end) as sisa")
                ->groupBy('batch_id')->orderByDesc('created_at')->limit(8)->with(['profile:id,name', 'router:id,name'])->get(),
            'chart' => SalesLog::where('sold_at', '>=', $today->subDays(6))->selectRaw('date(sold_at) as day, count(*) as cnt, coalesce(sum(price), 0) as revenue')->groupBy('day')->orderBy('day')->get(),
            'pppoe' => PppoeCustomer::selectRaw("count(*) as total, sum(case when status = 'active' then 1 else 0 end) as active, sum(case when status = 'isolated' then 1 else 0 end) as isolated")->first(),
            'pppoePaidMonth' => (float) PppoePayment::where('period_month', $today->month)->where('period_year', $today->year)->where('midtrans_status', 'paid')->sum('amount'),
            'wgTotal' => $wgKeys->count(),
            'wgOnline' => $wgKeys->filter(fn ($k) => ($peerStatus[$k]['handshake'] ?? 0) > now()->subMinutes(3)->timestamp)->count(),
            'wgForwards' => WgPortForward::count(),
            'ontTotal' => OntDevice::count(),
            'ontOnline' => OntDevice::where('last_inform_at', '>=', now()->subSeconds((int) config('ontconfig.online_threshold_seconds')))->count(),
        ]);
    }
}

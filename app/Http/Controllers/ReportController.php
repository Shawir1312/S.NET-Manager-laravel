<?php

namespace App\Http\Controllers;

use App\Models\HotspotProfile;
use App\Models\Penagihan;
use App\Models\Router;
use App\Models\SalesLog;
use App\Models\Voucher;
use App\Services\Hotspot\ResellerBilling;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $from = CarbonImmutable::parse($request->query('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse($request->query('to', now()->toDateString()))->endOfDay();

        return [$from, $to];
    }

    public function sales(Request $request): View
    {
        Gate::authorize('operate');
        [$from, $to] = $this->range($request);
        $base = SalesLog::from('sales_log as sl')->whereBetween('sl.sold_at', [$from, $to])
            ->when($request->integer('router_id'), fn ($q, $id) => $q->where('sl.router_id', $id))
            ->when($request->integer('profile_id'), fn ($q, $id) => $q->where('sl.profile_id', $id));

        return view('reports.sales', [
            'filter_from' => $from->toDateString(), 'filter_to' => $to->toDateString(),
            'filter_router' => $request->integer('router_id'), 'filter_profile' => $request->integer('profile_id'),
            'all_routers' => Router::orderBy('name')->get(['id', 'name'])->toArray(),
            'profiles' => HotspotProfile::orderBy('name')->get(['id', 'name'])->toArray(),
            'summary' => ['cnt' => (clone $base)->count(), 'total' => (float) (clone $base)->sum('sl.price')],
            'by_day' => (clone $base)->selectRaw('date(sl.sold_at) as day, count(*) as cnt, sum(sl.price) as revenue')->groupByRaw('date(sl.sold_at)')->orderBy('day')->get()->toArray(),
            'by_profile' => (clone $base)->leftJoin('routers as r', 'r.id', '=', 'sl.router_id')
                ->selectRaw('sl.profile_id, sl.profile_name, sl.router_id, r.name as router_name, count(*) as cnt, coalesce(sum(sl.price), 0) as revenue')
                ->groupBy('sl.router_id', 'sl.profile_id', 'sl.profile_name', 'r.name')->orderBy('r.name')->orderByDesc('revenue')->get()->toArray(),
            'details' => (clone $base)->leftJoin('routers as r', 'r.id', '=', 'sl.router_id')->leftJoin('users as u', 'u.id', '=', 'sl.sold_by')
                ->select('sl.*', 'r.name as router_name', 'u.name as sold_by_name')->orderByDesc('sl.sold_at')->limit(500)->get()
                ->map(fn ($r) => $r->getAttributes())->all(),
        ]);
    }

    public function salesDestroy(SalesLog $sale): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $sale->delete();

        return back()->with('status', 'Data penjualan dihapus.');
    }

    /** Kosongkan sales_log & penagihan lalu bangun ulang 1 penjualan per voucher yang benar-benar terpakai. */
    public function salesRebuild(): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $n = DB::transaction(function () {
            SalesLog::query()->delete();
            Penagihan::query()->delete();
            $n = 0;
            Voucher::with('profile')->whereIn('status', ['active', 'expired'])->whereNotNull('used_at')
                ->whereHas('profile', fn ($q) => $q->where('include_in_sales', true))
                ->each(function (Voucher $v) use (&$n) {
                    SalesLog::create(['voucher_id' => $v->id, 'voucher_username' => $v->username, 'profile_id' => $v->profile_id, 'profile_name' => $v->profile->name,
                        'router_id' => $v->router_id, 'price' => $v->profile->price, 'sold_at' => $v->used_at]);
                    $n++;
                });

            return $n;
        });

        return back()->with('status', "Pendapatan dihitung ulang: {$n} transaksi voucher.");
    }

    public function salesExport(Request $request): StreamedResponse
    {
        Gate::authorize('operate');
        [$from, $to] = $this->range($request);
        $rows = SalesLog::whereBetween('sold_at', [$from, $to])->when($request->integer('router_id'), fn ($q, $id) => $q->where('router_id', $id))->orderBy('sold_at')->cursor();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Waktu', 'Voucher', 'Profil', 'Router ID', 'Harga']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->sold_at?->format('Y-m-d H:i'), $r->voucher_username, $r->profile_name, $r->router_id, $r->price]);
            }
            fclose($out);
        }, 'penjualan-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    private const MONTHS_ID = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** Filter periode laporan pemakaian: mode bulan (default) atau rentang tanggal. @return array<string, mixed> */
    private function usagePeriod(Request $request): array
    {
        $mode = $request->query('mode', $request->has('from') && ! $request->has('month') ? 'range' : 'month') === 'range' ? 'range' : 'month';
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : now()->format('Y-m');
        $first = CarbonImmutable::parse("{$month}-01");
        [$from, $to] = $mode === 'month' ? [$first, $first->endOfMonth()] : $this->range($request);

        return ['mode' => $mode, 'filter_month' => $month, 'first' => $first, 'from' => $from->startOfDay(), 'to' => $to->endOfDay()];
    }

    private function usageQuery(array $period, int $routerId): Builder
    {
        [$start, $end] = [$period['from'], $period['to']];
        $router = $routerId ? Router::find($routerId) : null;

        return DB::table('radacct as ra')
            ->when($period['mode'] === 'month', fn ($q) => $q->where(fn ($w) => $w->whereBetween('ra.acctstarttime', [$start, $end])->orWhereBetween('ra.acctstoptime', [$start, $end])
                ->when($period['filter_month'] === now()->format('Y-m'), fn ($x) => $x->orWhereNull('ra.acctstoptime'))),
                fn ($q) => $q->whereBetween('ra.acctstarttime', [$start, $end]))
            ->when($router, fn ($q) => $q->whereIn('ra.nasipaddress', array_values(array_filter([$router->ip_address, explode('/', (string) $router->nas_ip)[0]]))));
    }

    /** Pemakaian data & waktu online per user dari radacct (port pages/reports/usage.php). */
    public function usage(Request $request): View
    {
        Gate::authorize('operate');
        $period = $this->usagePeriod($request);
        $routerId = $request->integer('router_id');
        $routers = Router::orderBy('name')->get(['id', 'name', 'ip_address', 'nas_ip']);
        $routerByIp = $routers->flatMap(fn ($r) => array_fill_keys(array_filter([$r->ip_address, explode('/', (string) $r->nas_ip)[0]]), $r->name));

        // Durasi di-clamp ke periode terpilih (dihitung di PHP agar portabel MySQL/SQLite).
        $sessions = $this->usageQuery($period, $routerId)->get(['ra.username', 'ra.nasipaddress', 'ra.acctstarttime', 'ra.acctstoptime', 'ra.acctinputoctets', 'ra.acctoutputoctets']);
        $clamp = fn ($s) => max(0, min($s->acctstoptime ? strtotime($s->acctstoptime) : time(), $period['to']->getTimestamp()) - max(strtotime((string) $s->acctstarttime), $period['from']->getTimestamp()));
        $customers = DB::table('pppoe_customers')->whereIn('pppoe_username', $sessions->pluck('username')->unique())->get(['pppoe_username', 'full_name', 'profile', 'status'])->keyBy('pppoe_username');

        $top = $sessions->groupBy(fn ($s) => $s->username."\0".$s->nasipaddress)->map(function ($g) use ($clamp, $routerByIp, $customers) {
            $f = $g->first();
            $c = $customers[$f->username] ?? null;

            return ['username' => $f->username, 'nasipaddress' => $f->nasipaddress, 'router_name' => $routerByIp[$f->nasipaddress] ?? $f->nasipaddress,
                'sessions' => $g->count(), 'total_secs' => $g->sum($clamp), 'dl' => $g->sum('acctoutputoctets'), 'ul' => $g->sum('acctinputoctets'),
                'total' => $g->sum('acctoutputoctets') + $g->sum('acctinputoctets'),
                'customer_name' => $c?->full_name, 'customer_profile' => $c?->profile, 'customer_status' => $c?->status];
        })->sortByDesc('total')->take(250)->values()->all();

        $chart = ['labels' => [], 'dl' => [], 'ul' => [], 'total' => []];
        if ($period['mode'] === 'month') {
            $daily = $sessions->groupBy(fn ($s) => substr((string) $s->acctstarttime, 0, 10));
            $gb = fn ($b) => round($b / 1073741824, 2);
            for ($d = 1; $d <= $period['first']->daysInMonth; $d++) {
                $day = $daily[$period['first']->day($d)->toDateString()] ?? collect();
                $chart['labels'][] = sprintf('%02d %s', $d, mb_substr(self::MONTHS_ID[$period['first']->month], 0, 3));
                $chart['dl'][] = $gb($day->sum('acctoutputoctets'));
                $chart['ul'][] = $gb($day->sum('acctinputoctets'));
                $chart['total'][] = $gb($day->sum('acctoutputoctets') + $day->sum('acctinputoctets'));
            }
        }

        $monthOptions = [];
        for ($i = 0; $i < 12; $i++) {
            $m = now()->startOfMonth()->subMonths($i);
            $monthOptions[$m->format('Y-m')] = ($i === 0 ? 'Bulan Ini (' : '').self::MONTHS_ID[$m->month].' '.$m->year.($i === 0 ? ')' : '');
        }

        return view('reports.usage', [
            'mode' => $period['mode'], 'filter_month' => $period['filter_month'], 'filter_router' => $routerId,
            'filter_from' => $period['from']->toDateString(), 'filter_to' => $period['to']->toDateString(),
            'all_routers' => $routers->map->only(['id', 'name'])->all(), 'month_options' => $monthOptions,
            'prev_month' => $period['first']->subMonth()->format('Y-m'), 'next_month' => $period['first']->addMonth()->format('Y-m'),
            'selected_month_label' => $monthOptions[$period['filter_month']] ?? self::MONTHS_ID[$period['first']->month].' '.$period['first']->year,
            'summary' => ['sessions' => $sessions->count(), 'total_users' => $sessions->pluck('username')->unique()->count(), 'total_secs' => $sessions->sum($clamp),
                'total_dl' => $sessions->sum('acctoutputoctets'), 'total_ul' => $sessions->sum('acctinputoctets'),
                'total_bytes' => $sessions->sum('acctoutputoctets') + $sessions->sum('acctinputoctets')],
            'top_users' => $top, 'max_user_bytes' => max(1, (float) ($top[0]['total'] ?? 0)),
            'chart_labels' => $chart['labels'], 'chart_dl' => $chart['dl'], 'chart_ul' => $chart['ul'], 'chart_total' => $chart['total'],
        ]);
    }

    public function usageExport(Request $request): StreamedResponse
    {
        Gate::authorize('operate');
        $period = $this->usagePeriod($request);
        $rows = $this->usageQuery($period, $request->integer('router_id'))->orderBy('ra.acctstarttime')->cursor();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Username', 'NAS', 'Mulai', 'Selesai', 'Durasi (detik)', 'Download (byte)', 'Upload (byte)', 'IP', 'MAC']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->username, $r->nasipaddress, $r->acctstarttime, $r->acctstoptime, $r->acctsessiontime, $r->acctoutputoctets, $r->acctinputoctets, $r->framedipaddress, $r->callingstationid]);
            }
            fclose($out);
        }, 'pemakaian-'.$period['from']->format('Ymd').'-'.$period['to']->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Hapus riwayat sesi (yang sudah selesai) milik satu user pada periode terpilih. */
    public function usageDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate(['username' => ['required', 'string', 'max:64'], 'nasipaddress' => ['required', 'string', 'max:64']]);
        $n = $this->usageQuery($this->usagePeriod($request), 0)->where('ra.username', $d['username'])->where('ra.nasipaddress', $d['nasipaddress'])
            ->whereNotNull('ra.acctstoptime')->delete();

        return back()->with('status', "{$n} sesi {$d['username']} dihapus.");
    }

    private function penagihanHistory(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Penagihan::query()->from('penagihan as p')->join('routers as r', 'r.id', '=', 'p.router_id')->join('profiles as pr', 'pr.id', '=', 'p.profile_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.ditagih_oleh')
            ->whereDate('p.tanggal', '>=', (string) $request->query('start_date', now()->startOfMonth()->toDateString()))
            ->whereDate('p.tanggal', '<=', (string) $request->query('end_date', now()->toDateString()))
            ->when($request->integer('router_id'), fn ($q, $id) => $q->where('p.router_id', $id))
            ->select('p.*', 'r.name as router_name', 'pr.name as profile_name', 'u.name as admin_name')->orderByDesc('p.created_at');
    }

    /** Laporan penagihan reseller (port pages/reports/penagihan.php). */
    public function penagihan(Request $request): View
    {
        Gate::authorize('operate');
        $routers = Router::orderBy('name')->get(['id', 'name']);
        $history = $this->penagihanHistory($request)->get()->map(fn ($h) => $h->getAttributes())->all();
        $perRouter = $routers->mapWithKeys(fn ($r) => [$r->name => ['kotor' => 0, 'bersih' => 0, 'transaksi' => 0]])->all();
        foreach ($history as $h) {
            $name = $h['router_name'] ?: 'Tanpa Router';
            $perRouter[$name] ??= ['kotor' => 0, 'bersih' => 0, 'transaksi' => 0];
            $perRouter[$name]['kotor'] += (float) $h['total_pendapatan'];
            $perRouter[$name]['bersih'] += (float) $h['pendapatan_bersih'];
            $perRouter[$name]['transaksi']++;
        }

        return view('reports.penagihan', [
            'admin' => ['id' => $request->user()->id], 'all_routers' => $routers->toArray(), 'history' => $history,
            'filter_start' => $request->query('start_date', now()->startOfMonth()->toDateString()), 'filter_end' => $request->query('end_date', now()->toDateString()),
            'router_filter' => $request->integer('router_id'), 'total_transaksi' => count($history), 'totals_per_router' => $perRouter,
            'total_kotor' => array_sum(array_column($history, 'total_pendapatan')), 'total_bersih' => array_sum(array_column($history, 'pendapatan_bersih')),
        ]);
    }

    /** Daftar profil reseller per cabang + ringkasan tagihan (port process/get_resellers.php). */
    public function penagihanResellers(Request $request, ResellerBilling $billing): JsonResponse
    {
        Gate::authorize('operate');
        $router = Router::findOrFail($request->integer('router_id'));

        return response()->json(HotspotProfile::with('router:id,name')->where('is_active', true)->where('reseller_percent', '>', 0)
            ->where(fn ($q) => $q->where('router_id', $router->id)->orWhereNull('router_id'))->orderBy('name')->get()
            ->map(function (HotspotProfile $p) use ($router, $billing) {
                $s = $billing->summary($router, $p);

                return ['id' => $p->id, 'name' => $p->name, 'reseller_percent' => (float) $p->reseller_percent, 'price' => (float) $p->price,
                    'router_id' => $p->router_id, 'router_name' => $p->router?->name,
                    'last_billed_date' => $s['last']?->created_at?->format('d/m/Y H:i') ?? 'Belum Pernah', 'last_status' => $s['last']?->status_kecocokan,
                    'vcr_baru' => $s['new_vouchers'], 'sisa_sebelumnya' => $s['carry'], 'voucher_aktual' => $s['actual'],
                    'tekor_count' => Penagihan::where('router_id', $router->id)->where('profile_id', $p->id)->where('status_kecocokan', 'tekor')->count()];
            }));
    }

    /** Titik awal baru: catat penagihan Rp 0 untuk semua reseller agar voucher lama dianggap lunas. */
    public function penagihanBaseline(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $active = Router::where('status', 'active')->pluck('id');
        $n = 0;
        foreach (HotspotProfile::where('is_active', true)->where('reseller_percent', '>', 0)->get() as $p) {
            foreach ($p->router_id ? [$p->router_id] : $active as $routerId) {
                Penagihan::create(['router_id' => $routerId, 'profile_id' => $p->id, 'total_pendapatan' => 0, 'bagian_reseller' => 0, 'pendapatan_bersih' => 0,
                    'estimasi_voucher' => 0, 'voucher_aktual' => 0, 'status_kecocokan' => 'sesuai', 'catatan' => 'Titik Awal Baru (Reset Baseline Saldo 0)',
                    'ditagih_oleh' => $request->user()->id, 'tanggal' => now()->toDateString()]);
                $n++;
            }
        }

        return back()->with('status', "Titik awal baru disetel untuk {$n} kombinasi reseller/cabang. Target tagihan dimulai dari 0 voucher.");
    }

    /** Hapus penagihan: hanya milik sendiri & dibuat hari ini (admin boleh semua). */
    public function penagihanDestroy(Request $request, Penagihan $penagihan): RedirectResponse
    {
        Gate::authorize('operate');
        abort_unless($request->user()->can('manage-settings')
            || ($penagihan->ditagih_oleh === $request->user()->id && $penagihan->created_at?->isToday()), 403);
        $penagihan->delete();

        return back()->with('status', 'Data penagihan dihapus.');
    }

    public function penagihanExport(Request $request): StreamedResponse
    {
        Gate::authorize('operate');
        $rows = $this->penagihanHistory($request)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Waktu', 'Cabang', 'Reseller', 'Total Pendapatan', 'Bagian Reseller', 'Pendapatan Bersih', 'Estimasi Voucher', 'Voucher Aktual', 'Status', 'Catatan', 'Ditagih Oleh']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->created_at, $r->router_name, $r->profile_name, $r->total_pendapatan, $r->bagian_reseller, $r->pendapatan_bersih,
                    $r->estimasi_voucher, $r->voucher_aktual, $r->status_kecocokan, $r->catatan, $r->admin_name]);
            }
            fclose($out);
        }, 'penagihan-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function penagihanStore(Request $request, ResellerBilling $billing): RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate([
            'router_id' => ['required', 'exists:routers,id'], 'profile_id' => ['required', 'exists:profiles,id'],
            'total_pendapatan' => ['required', 'numeric', 'min:1'], 'catatan' => ['nullable', 'string', 'max:500'],
        ]);
        $p = $billing->record(Router::findOrFail($d['router_id']), HotspotProfile::findOrFail($d['profile_id']), (float) $d['total_pendapatan'], $request->user(), $d['catatan'] ?? '', $request->boolean('ignore_previous'));

        return back()->with('status', "Penagihan dicatat: {$p->status_kecocokan} (estimasi {$p->estimasi_voucher}, aktual {$p->voucher_aktual} voucher).");
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\Hotspot\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers) {}

    public function index(Request $request): View
    {
        Gate::authorize('operate');
        $q = trim((string) $request->query('q'));
        $batch = (string) $request->query('batch_id', '');
        $status = (string) $request->query('status', $batch !== '' ? 'unused' : '');
        $list = Voucher::with(['profile:id,name,price', 'router:id,name'])
            ->when(in_array($status, ['unused', 'active', 'expired'], true), fn ($b) => $b->where('status', $status), fn ($b) => $b->where('status', '!=', 'deleted'))
            ->when($request->filled('router_id'), fn ($b) => $b->where('router_id', $request->integer('router_id')))
            ->when($request->filled('profile_id'), fn ($b) => $b->where('profile_id', $request->integer('profile_id')))
            ->when($batch !== '', fn ($b) => $b->where('batch_id', $batch))
            ->when($q !== '', fn ($b) => $b->where('username', 'like', "%{$q}%"))
            ->latest('id')->paginate(50)->withQueryString();

        return view('hotspot.vouchers', [
            'vouchers' => $list,
            'profiles' => HotspotProfile::orderBy('name')->get(['id', 'name']),
            'all_routers' => Router::orderBy('name')->get(['id', 'name']),
            'batch_list' => Voucher::query()->leftJoin('profiles', 'vouchers.profile_id', '=', 'profiles.id')
                ->whereNotNull('vouchers.batch_id')->where('vouchers.batch_id', '!=', '')->where('vouchers.status', '!=', 'deleted')
                ->groupBy('vouchers.batch_id', 'profiles.name')
                ->selectRaw("vouchers.batch_id, max(vouchers.created_at) as created_date, sum(case when vouchers.status = 'unused' then 1 else 0 end) as sisa_count, profiles.name as profile_name")
                ->orderByRaw('max(vouchers.created_at) desc')->limit(100)->get(),
            'filter_status' => $status,
            'filter_batch' => $batch,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('operate');
        $last = Voucher::where('generated_by', $request->user()->id)->whereNotNull('batch_id')->latest('id')->first();

        return view('hotspot.generate', [
            'all_routers' => Router::orderBy('name')->get(['id', 'name'])->toArray(),
            'profiles' => HotspotProfile::where('is_active', true)->orderBy('name')->get()->toArray(),
            'preselect_profile' => $request->integer('profile_id') ?: null,
            'last_batch' => $last ? [
                'batch_id' => $last->batch_id, 'profile_name' => $last->profile?->name, 'router_name' => $last->router?->name,
                'qty' => Voucher::where('batch_id', $last->batch_id)->count(), 'created_at' => (string) $last->created_at,
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate([
            'profile_id' => ['required', 'exists:profiles,id'],
            'qty' => ['required', 'integer', 'between:1,'.VoucherService::MAX_BATCH],
            'user_mode' => ['required', 'in:vc,up'],
            'char_length' => ['required', 'integer', 'between:4,16'],
            'char_type' => ['required', 'in:'.implode(',', array_keys(VoucherService::CHARSETS))],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]*$/'],
        ]);
        $profile = HotspotProfile::where('is_active', true)->findOrFail($d['profile_id']);
        $r = $this->vouchers->generate($profile, $d['qty'], $d['user_mode'], $d['char_length'], $d['char_type'], $d['prefix'] ?? '', $request->user());

        return redirect()->route('vouchers.print', ['batch_id' => $r['batch_id']])->with('status', "{$r['count']} voucher dibuat (batch {$r['batch_id']}).");
    }

    public function print(Request $request): View|RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate(['batch_id' => ['nullable', 'string', 'max:64'], 'voucher_id' => ['nullable', 'integer'], 'profile_id' => ['nullable', 'integer']]);
        if (empty($d['batch_id']) && empty($d['voucher_id'])) {
            return redirect()->route('vouchers.index')->withErrors(['print' => 'Tidak ada voucher yang dipilih untuk dicetak.']);
        }
        $vouchers = Voucher::with(['profile', 'router:id,name'])->where('status', '!=', 'deleted')
            ->when($d['batch_id'] ?? null, fn ($b, $v) => $b->where('batch_id', $v), fn ($b) => $b->whereKey($d['voucher_id']))
            ->when($d['profile_id'] ?? null, fn ($b, $v) => $b->where('profile_id', $v))
            ->orderBy('id')->get();
        if ($vouchers->isEmpty()) {
            return redirect()->route('vouchers.index')->withErrors(['print' => 'Voucher tidak ditemukan.']);
        }

        return view('hotspot.print', ['vouchers' => $vouchers, 'batch' => $d['batch_id'] ?? null]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Gate::authorize('operate');
        $n = 0;
        foreach (Voucher::whereIn('id', $this->ids($request))->where('status', '!=', 'deleted')->get() as $v) {
            $this->vouchers->retire($v);
            $n++;
        }

        return back()->with('status', "{$n} voucher dihapus dari RADIUS.");
    }

    /** Hapus massal berdasarkan profil / batch / status (modal "Hapus Massal"). */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate([
            'profile_id' => ['nullable', 'integer'], 'batch_id' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:unused,active,expired'], 'confirm_all' => ['accepted'],
        ]);
        if (empty($d['profile_id']) && empty($d['batch_id']) && empty($d['status'])) {
            return back()->withErrors(['bulk' => 'Pilih minimal satu kriteria (profil, batch, atau status).']);
        }
        $n = 0;
        Voucher::where('status', '!=', 'deleted')
            ->when($d['profile_id'] ?? null, fn ($b, $v) => $b->where('profile_id', $v))
            ->when($d['batch_id'] ?? null, fn ($b, $v) => $b->where('batch_id', $v))
            ->when($d['status'] ?? null, fn ($b, $v) => $b->where('status', $v))
            ->each(function (Voucher $v) use (&$n) {
                $this->vouchers->retire($v);
                $n++;
            });

        return back()->with('status', "{$n} voucher dihapus secara massal.");
    }

    /** Pulihkan voucher kadaluarsa: kembali ke "Belum Dipakai" dan tulis ulang ke FreeRADIUS. */
    public function reactivate(Request $request): RedirectResponse
    {
        Gate::authorize('operate');
        $n = 0;
        foreach (Voucher::with('profile')->whereIn('id', $this->ids($request))->whereIn('status', ['expired', 'active'])->get() as $v) {
            if ($v->profile) {
                $v->update(['status' => 'unused', 'used_at' => null, 'expired_at' => null]);
                $this->vouchers->writeRadius($v->username, $v->password, $v->profile);
                $n++;
            }
        }

        return back()->with('status', "{$n} voucher dipulihkan.");
    }

    /** @return list<int> dari ids[] / "1,2,3" / voucher_id / voucher_ids */
    private function ids(Request $request): array
    {
        $raw = $request->input('ids', $request->input('voucher_ids', $request->input('voucher_id', [])));
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw))));
        abort_if($ids === [] || count($ids) > 500, 422, 'Tidak ada voucher yang dipilih.');

        return $ids;
    }
}

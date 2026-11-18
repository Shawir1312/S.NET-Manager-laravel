<?php

namespace App\Http\Controllers;

use App\Models\HotspotProfile;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use App\Services\Hotspot\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HotspotProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('manage-settings');

        return view('hotspot.profiles', [
            'profiles' => HotspotProfile::with('router:id,name')
                ->withCount(['vouchers as voucher_count' => fn ($q) => $q->whereIn('status', ['unused', 'active'])])
                ->when($request->integer('router_id'), fn ($q, $id) => $q->where('router_id', $id))
                ->orderBy('name')->get(),
            'all_routers' => Router::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Terapkan atribut RADIUS tiap profil ke seluruh voucher belum terpakai. */
    public function syncAll(VoucherService $vouchers): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $n = HotspotProfile::all()->sum(fn (HotspotProfile $p) => $vouchers->syncProfileToUnused($p));

        return back()->with('status', "{$n} voucher disinkronkan ke FreeRADIUS.");
    }

    public function create(): View
    {
        Gate::authorize('manage-settings');

        return view('hotspot.profile-form', ['profile' => new HotspotProfile(['validity_value' => 30, 'validity_unit' => 'days', 'duration_value' => 0, 'duration_unit' => 'hours', 'is_active' => true, 'include_in_sales' => true]), 'routers' => Router::orderBy('name')->get(['id', 'name', 'ip_address'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $p = HotspotProfile::create($this->validated($request));
        $this->audit->log('hotspot.profile_created', $p, ['name' => $p->name]);

        return redirect()->route('hotspot-profiles.index')->with('status', "Profil {$p->name} dibuat.");
    }

    public function edit(HotspotProfile $hotspotProfile): View
    {
        Gate::authorize('manage-settings');

        return view('hotspot.profile-form', ['profile' => $hotspotProfile, 'routers' => Router::orderBy('name')->get(['id', 'name', 'ip_address'])]);
    }

    public function update(Request $request, HotspotProfile $hotspotProfile, VoucherService $vouchers): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $hotspotProfile->update($this->validated($request));
        $n = $request->boolean('sync_existing_vouchers') ? $vouchers->syncProfileToUnused($hotspotProfile) : 0;
        $this->audit->log('hotspot.profile_updated', $hotspotProfile, ['name' => $hotspotProfile->name, 'vouchers_synced' => $n]);

        return redirect()->route('hotspot-profiles.index')->with('status', "Profil {$hotspotProfile->name} disimpan; {$n} voucher belum terpakai ikut diperbarui.");
    }

    public function destroy(HotspotProfile $hotspotProfile): RedirectResponse
    {
        Gate::authorize('manage-settings');
        if ($hotspotProfile->vouchers()->whereIn('status', ['unused', 'active'])->exists()) {
            return back()->withErrors(['profile' => 'Masih ada voucher aktif/belum terpakai di profil ini. Nonaktifkan profil saja.']);
        }
        $hotspotProfile->delete();

        return back()->with('status', 'Profil dihapus.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $unit = ['required', 'in:minutes,hours,days'];

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'router_id' => ['nullable', 'exists:routers,id'],
            'validity_value' => ['required', 'integer', 'min:1'], 'validity_unit' => $unit,
            'duration_value' => ['required', 'integer', 'min:0'], 'duration_unit' => $unit,
            'quota_mb' => ['nullable', 'integer', 'min:0'],
            'rate_up' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+[kKmMgG]?$/'],
            'rate_down' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+[kKmMgG]?$/'],
            'price' => ['required', 'numeric', 'min:0'],
            'reseller_percent' => ['nullable', 'numeric', 'between:0,100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]) + ['is_active' => $request->boolean('is_active'), 'include_in_sales' => $request->boolean('include_in_sales'), 'quota_mb' => (int) $request->input('quota_mb'), 'reseller_percent' => (float) $request->input('reseller_percent')];
    }
}

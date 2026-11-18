<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationTemplate;
use App\Models\GenieAcsSetting;
use App\Models\HotspotProfile;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use App\Services\Hotspot\VoucherService;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;
use App\Services\Radius\RadiusSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Router MikroTik + baris `nas` FreeRADIUS-nya. */
class RouterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('manage-settings');

        return view('routers.index', ['routers' => Router::with('genieAcsSetting')->withCount('customers')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('manage-settings');

        return view('routers.form', ['router' => new Router(['api_port' => 8728, 'api_user' => 'admin', 'status' => 'active', 'default_vlan' => 100, 'nas_ip' => '0.0.0.0/0'])] + $this->options());
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $router = DB::transaction(function () use ($request) {
            $router = Router::create($this->validated($request));
            $this->syncNas($router);

            return $router;
        });
        $this->audit->log('router.created', $router, ['name' => $router->name]);

        return redirect()->route('routers.index')->with('status', "Router {$router->name} ditambahkan.");
    }

    public function edit(Router $router): View
    {
        Gate::authorize('manage-settings');

        return view('routers.form', compact('router') + $this->options());
    }

    public function update(Request $request, Router $router): RedirectResponse
    {
        Gate::authorize('manage-settings');
        DB::transaction(function () use ($request, $router) {
            $router->update($this->validated($request, $router));
            $this->syncNas($router);
        });
        $this->audit->log('router.updated', $router, ['name' => $router->name]);

        return redirect()->route('routers.index')->with('status', "Router {$router->name} diperbarui.");
    }

    public function destroy(Router $router): RedirectResponse
    {
        Gate::authorize('manage-settings');
        if ($router->customers()->exists()) {
            return back()->withErrors(['router' => 'Router masih punya pelanggan PPPoE. Pindahkan/hapus pelanggan dulu.']);
        }
        DB::transaction(function () use ($router) {
            DB::table('nas')->where('id', $router->nas_id)->delete();
            $router->delete();
        });
        $this->audit->log('router.deleted', null, ['name' => $router->name]);

        return redirect()->route('routers.index')->with('status', "Router {$router->name} dihapus.");
    }

    /** Uji koneksi API MikroTik (JSON untuk tombol/ badge status di daftar router). */
    public function test(Request $request, Router $router, MikrotikConnector $mikrotik): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-settings');
        try {
            [$identity, $users] = $mikrotik->with($router, fn (MikrotikConnection $api) => [
                $api->comm('/system/identity/print')[0]['name'] ?? '?',
                count($api->comm('/ip/hotspot/active/print')) + count($api->comm('/ppp/active/print')),
            ]);
            $router->forceFill(['last_seen' => now()])->save();
            $message = "Terhubung ke {$router->name} (identity: {$identity}).";

            return $request->wantsJson() ? response()->json(['success' => true, 'online' => true, 'identity' => $identity, 'users' => $users, 'message' => $message])
                : back()->with('status', $message);
        } catch (MikrotikException $e) {
            return $request->wantsJson() ? response()->json(['success' => false, 'online' => false, 'message' => $e->getMessage()])
                : back()->withErrors(['router' => $e->getMessage()]);
        }
    }

    /** Tulis ulang tabel nas, akun PPPoE, dan voucher belum terpakai ke FreeRADIUS. */
    public function syncRadius(RadiusSync $radius, VoucherService $vouchers): RedirectResponse
    {
        Gate::authorize('manage-settings');
        Router::all()->each(fn (Router $r) => $this->syncNas($r));
        $customers = 0;
        PppoeCustomer::query()->each(function (PppoeCustomer $c) use ($radius, &$customers) {
            $radius->syncCustomer($c);
            $customers++;
        });
        $voucherCount = HotspotProfile::all()->sum(fn (HotspotProfile $p) => $vouchers->syncProfileToUnused($p));
        $this->audit->log('radius.synced', null, ['customers' => $customers, 'vouchers' => $voucherCount]);

        return back()->with('status', "Sinkronisasi FreeRADIUS selesai: router NAS, {$customers} akun PPPoE, {$voucherCount} voucher.");
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'genieServers' => GenieAcsSetting::orderBy('name')->get(['id', 'name', 'nbi_url']),
            'templates' => ConfigurationTemplate::orderBy('name')->get(['id', 'name', 'service_type']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Router $router = null): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ip_address' => ['required', 'ip', Rule::unique('routers')->ignore($router?->id)],
            'nas_ip' => ['required', 'string', 'max:128'],
            'api_user' => ['required', 'string', 'max:64'],
            'api_password' => [$router ? 'nullable' : 'required', 'string', 'max:128'],
            'api_port' => ['required', 'integer', 'between:1,65535'],
            'radius_secret' => ['required', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:active,inactive'],
            'genie_acs_setting_id' => ['nullable', 'exists:genieacs_settings,id'],
            'default_vlan' => ['required', 'integer', 'between:1,4094'],
            'pppoe_template_id' => ['nullable', Rule::exists('configuration_templates', 'id')->where('service_type', 'pppoe')],
            'hotspot_template_id' => ['nullable', Rule::exists('configuration_templates', 'id')->where('service_type', 'bridge')],
        ]);
        if (blank($d['api_password'] ?? null)) {
            unset($d['api_password']); // edit: kosong = tidak diubah
        }

        return $d;
    }

    /** FreeRADIUS hanya menerima request dari NAS yang terdaftar di tabel `nas`. */
    private function syncNas(Router $router): void
    {
        $row = ['nasname' => $router->nas_ip, 'shortname' => mb_substr($router->name, 0, 32), 'type' => 'mikrotik', 'secret' => $router->radius_secret, 'description' => 'S.NET: '.$router->name];
        if ($router->nas_id && DB::table('nas')->where('id', $router->nas_id)->exists()) {
            DB::table('nas')->where('id', $router->nas_id)->update($row);
        } else {
            $router->forceFill(['nas_id' => DB::table('nas')->insertGetId($row)])->save();
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\WgLog;
use App\Models\WgPortForward;
use App\Models\WgRouter;
use App\Services\Settings\SnetSettings;
use App\Services\WireGuard\WireGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WireGuardController extends Controller
{
    public function __construct(private readonly WireGuardService $wg) {}

    public function index(Request $request): View
    {
        Gate::authorize('manage-settings');
        $tab = in_array($request->query('tab'), ['forwards', 'settings', 'logs'], true) ? $request->query('tab') : 'routers';
        $config = $this->wg->config();

        return match ($tab) {
            'forwards' => view('wireguard.forwards', [
                'routers' => WgRouter::orderBy('name')->get(['id', 'name', 'tunnel_ip']),
                'forwards' => WgPortForward::with('router:id,name,tunnel_ip')
                    ->when($request->integer('router_id'), fn ($q, $id) => $q->where('router_id', $id))->orderBy('public_port')->get(),
                'selRouterId' => $request->integer('router_id'), 'vpsHost' => $this->wg->remoteHost(),
            ]),
            'settings' => view('wireguard.settings', ['settings' => $config] + $this->wg->serverStatus()),
            'logs' => view('wireguard.logs', ['logs' => WgLog::latest('id')->limit(100)->get()]),
            default => view('wireguard.index', [
                'routers' => WgRouter::withCount('portForwards')->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(
                    fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('tunnel_ip', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%")->orWhere('lan_subnets', 'like', "%{$s}%")
                ))->latest('id')->get(),
                'peerStatus' => $this->wg->peerStatus(),
                'search' => trim((string) $request->query('search')),
            ]),
        };
    }

    public function create(): View
    {
        Gate::authorize('manage-settings');

        return view('wireguard.form', ['router' => new WgRouter(['tunnel_ip' => $this->wg->nextTunnelIp(), 'listen_port' => 13231] + WireGuardService::keypair()), 'settings' => $this->wg->config()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $router = WgRouter::create($this->validated($request));
        $this->wg->syncPeer($router);

        return redirect()->route('wireguard.show', $router)->with('status', "Router {$router->name} dibuat. Tempel skrip di bawah ke terminal MikroTik.");
    }

    public function show(WgRouter $wireguard): View
    {
        Gate::authorize('manage-settings');

        return view('wireguard.show', [
            'router' => $wireguard, 'script' => $this->wg->mikrotikScript($wireguard), 'clientConf' => $this->wg->clientConf($wireguard),
            'st' => $this->wg->peerStatus()[$wireguard->public_key] ?? null, 'forwards' => $wireguard->portForwards()->orderBy('public_port')->get(),
            'vpsHost' => $this->wg->remoteHost(),
        ]);
    }

    public function edit(WgRouter $wireguard): View
    {
        Gate::authorize('manage-settings');

        return view('wireguard.form', ['router' => $wireguard, 'settings' => $this->wg->config()]);
    }

    public function update(Request $request, WgRouter $wireguard): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $wireguard->update($this->validated($request, $wireguard));
        $this->wg->syncPeer($wireguard);

        return redirect()->route('wireguard.show', $wireguard)->with('status', 'Router diperbarui.');
    }

    public function destroy(WgRouter $wireguard): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $wireguard->portForwards->each(fn (WgPortForward $pf) => $this->wg->applyPortForward($pf, false));
        $this->wg->removePeer($wireguard);
        $wireguard->delete();

        return redirect()->route('wireguard.index')->with('status', 'Router WireGuard dihapus.');
    }

    public function conf(WgRouter $wireguard): Response
    {
        Gate::authorize('manage-settings');

        return response($this->wg->clientConf($wireguard), 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => 'attachment; filename="'.str($wireguard->name)->slug().'.conf"']);
    }

    /** Alat AJAX (port dari process/wireguard/ajax_tools.php). */
    public function tools(Request $request): JsonResponse
    {
        Gate::authorize('manage-settings');
        $action = (string) $request->input('action');
        abort_if(in_array($action, ['sync_all_peers', 'sync_firewall', 'restart_service'], true) && ! $request->isMethod('post'), 405);

        return response()->json(match ($action) {
            'generate_keys' => WireGuardService::keypair(),
            'get_next_ip' => ['ip' => $this->wg->nextTunnelIp()],
            'ping' => ['output' => $this->wg->ping($this->routerByIp($request)->tunnel_ip)],
            'port_check' => $this->wg->testPort($this->routerByIp($request), (int) $request->validate(['port' => ['required', 'in:8728,8291,80,22']])['port']),
            'sync_all_peers' => $this->wg->syncAll(),
            'sync_firewall' => ['success' => true, 'count' => WgPortForward::with('router')->get()->each(function (WgPortForward $pf) {
                $this->wg->applyPortForward($pf, false);
                $this->wg->applyPortForward($pf);
            })->count()],
            'restart_service' => $this->wg->restartService(),
            default => ['success' => false, 'message' => 'Aksi tidak dikenal.'],
        });
    }

    /** Diagnostik hanya untuk IP tunnel router yang terdaftar. */
    private function routerByIp(Request $request): WgRouter
    {
        return WgRouter::where('tunnel_ip', $request->validate(['ip' => ['required', 'ipv4']])['ip'])->firstOrFail();
    }

    public function storeForward(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate([
            'router_id' => ['required', 'exists:wg_routers,id'],
            'public_port' => ['required', 'integer', 'between:1025,65535', Rule::unique('wg_port_forwards')->where('protocol', $request->input('protocol'))],
            'target_port' => ['required', 'integer', 'between:1,65535'],
            'target_ip' => ['nullable', 'ipv4'],
            'protocol' => ['required', 'in:tcp,udp'],
        ]);
        $this->wg->applyPortForward(WgPortForward::create($d));

        return redirect()->route('wireguard.index', ['tab' => 'forwards'])->with('status', "Port {$d['public_port']} diteruskan.");
    }

    public function destroyForward(WgPortForward $forward): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $this->wg->applyPortForward($forward, false);
        $forward->delete();

        return back()->with('status', 'Port forward dihapus.');
    }

    public function updateSettings(Request $request, SnetSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $request->merge(['wg_subnet_prefix' => preg_replace('/\.0(\/\d+)?$|\/\d+$/', '', trim((string) $request->input('wg_subnet_prefix'))).'.']);
        $d = $request->validate([
            'wg_server_endpoint' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+:[0-9]{2,5}$/'],
            'wg_listen_port' => ['required', 'integer', 'between:1024,65535'],
            'wg_remote_public_host' => ['nullable', 'string', 'max:255'],
            'wg_server_pubkey' => ['nullable', 'string', 'size:44'],
            'wg_server_privkey' => ['nullable', 'string', 'size:44'],
            'wg_subnet_prefix' => ['required', 'regex:/^([0-9]{1,3}\.){3}$/'],
            'wg_interface' => ['required', 'alpha_num', 'max:15'],
            'wg_remote_port_range' => ['required', 'regex:/^[0-9]{4,5}-[0-9]{4,5}$/'],
            'wg_dns' => ['nullable', 'string', 'max:100'],
            'wg_mtu' => ['nullable', 'integer', 'between:1280,1500'],
        ], ['wg_subnet_prefix.regex' => 'Subnet tunnel harus berformat CIDR /24, mis. 10.66.66.0/24.']);
        if (blank($d['wg_server_privkey'] ?? null)) {
            unset($d['wg_server_privkey']); // kosong = tidak diubah
        }
        $settings->put('wireguard', $d);

        return redirect()->route('wireguard.index', ['tab' => 'settings'])->with('status', 'Pengaturan WireGuard disimpan.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?WgRouter $router = null): array
    {
        $prefix = preg_quote(rtrim($this->wg->config()['wg_subnet_prefix'], '.').'.', '/');

        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'tunnel_ip' => ['required', 'ipv4', "regex:/^{$prefix}/", Rule::unique('wg_routers')->ignore($router?->id)],
            'listen_port' => ['required', 'integer', 'between:1024,65535'],
            'lan_subnets' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'private_key' => [$router ? 'nullable' : 'required', 'string', 'size:44', 'regex:/^[A-Za-z0-9+\/]{43}=$/'],
        ], ['tunnel_ip.regex' => 'IP tunnel harus di subnet '.rtrim($this->wg->config()['wg_subnet_prefix'], '.').'.0/24.']);
        if (blank($d['private_key'] ?? null)) {
            unset($d['private_key']);
        } else {
            $d['public_key'] = WireGuardService::publicKeyOf($d['private_key']); // public key selalu diturunkan agar pasangan kunci valid
        }

        return $d;
    }
}

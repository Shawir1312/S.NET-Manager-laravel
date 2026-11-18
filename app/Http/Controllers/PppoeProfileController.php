<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Profil PPP langsung di MikroTik (/ppp/profile), per router. */
class PppoeProfileController extends Controller
{
    public function __construct(private readonly MikrotikConnector $mikrotik) {}

    public function index(Request $request): View
    {
        Gate::authorize('manage-settings');
        $routers = Router::orderBy('name')->get();
        $router = $routers->firstWhere('id', $request->integer('router_id')) ?? $routers->first();

        $profiles = [];
        $error = null;
        if ($router) {
            try {
                $profiles = $this->mikrotik->with($router, fn (MikrotikConnection $api) => $api->comm('/ppp/profile/print'));
            } catch (MikrotikException $e) {
                $error = $e->getMessage();
            }
        }

        return view('pppoe.profiles', compact('routers', 'router', 'profiles', 'error'));
    }

    /** Form tambah/edit profil (halaman terpisah seperti S.NET lama); pool diambil dari /ip/pool. */
    public function form(Request $request): View|RedirectResponse
    {
        Gate::authorize('manage-settings');
        $router = Router::findOrFail($request->integer('router_id'));
        $id = (string) $request->query('id', '');
        $profile = ['name' => '', 'local-address' => '', 'remote-address' => '', 'rate-limit' => '', 'only-one' => 'default', 'comment' => ''];

        try {
            [$pools, $found] = $this->mikrotik->with($router, fn (MikrotikConnection $api) => [
                array_column($api->comm('/ip/pool/print', ['.proplist' => 'name']), 'name'),
                $id !== '' ? ($api->comm('/ppp/profile/print', ['?.id' => $id])[0] ?? null) : null,
            ]);
        } catch (MikrotikException $e) {
            return redirect()->route('pppoe-profiles.index', ['router_id' => $router->id])->withErrors(['router' => $e->getMessage()]);
        }
        if ($id !== '' && ! $found) {
            return redirect()->route('pppoe-profiles.index', ['router_id' => $router->id])->withErrors(['id' => 'Profil tidak ditemukan di MikroTik.']);
        }

        return view('pppoe.profile-form', ['router' => $router, 'id' => $id, 'profile' => array_merge($profile, $found ?? []), 'pools' => $pools]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate([
            'router_id' => ['required', 'exists:routers,id'],
            'id' => ['nullable', 'string', 'max:20', 'regex:/^\*[0-9A-F]+$/'],
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.\- ]+$/'],
            'local_address' => ['nullable', 'string', 'max:64'],
            'remote_address' => ['nullable', 'string', 'max:64'],
            'rate_limit' => ['nullable', 'string', 'max:64', 'regex:/^[0-9kKmMgG\/ ]*$/'],
            'only_one' => ['nullable', 'in:yes,no,default'],
            'comment' => ['nullable', 'string', 'max:128'],
        ]);
        $router = Router::findOrFail($d['router_id']);
        $cmd = array_filter([
            'local-address' => $d['local_address'] ?? null, 'remote-address' => $d['remote_address'] ?? null,
            'rate-limit' => $d['rate_limit'] ?? null, 'only-one' => $d['only_one'] ?? null, 'comment' => $d['comment'] ?? null,
        ], fn ($v) => filled($v));

        try {
            $this->mikrotik->with($router, fn (MikrotikConnection $api) => filled($d['id'] ?? null)
                ? $api->comm('/ppp/profile/set', ['.id' => $d['id'], 'name' => $d['name']] + $cmd)
                : $api->comm('/ppp/profile/add', ['name' => $d['name']] + $cmd));
        } catch (MikrotikException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('pppoe-profiles.index', ['router_id' => $router->id])->with('status', "Profil {$d['name']} disimpan ke {$router->name}.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate(['router_id' => ['required', 'exists:routers,id'], 'id' => ['required', 'string', 'regex:/^\*[0-9A-F]+$/']]);
        $router = Router::findOrFail($d['router_id']);

        try {
            $this->mikrotik->with($router, fn (MikrotikConnection $api) => $api->comm('/ppp/profile/remove', ['.id' => $d['id']]));
        } catch (MikrotikException $e) {
            return back()->withErrors(['id' => $e->getMessage()]);
        }

        return back()->with('status', 'Profil dihapus dari MikroTik.');
    }
}

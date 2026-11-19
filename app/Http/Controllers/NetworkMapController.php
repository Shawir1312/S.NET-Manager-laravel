<?php

namespace App\Http\Controllers;

use App\Models\Odp;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use App\Services\Network\NetworkMapData;
use App\Services\Settings\SnetSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Peta jaringan (admin/teknisi): kelola posisi server, ODP, pelanggan; status koneksi real-time. */
class NetworkMapController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, SnetSettings $settings): View
    {
        Gate::authorize('operate');
        $routers = Router::orderBy('name')->get(['id', 'name', 'latitude', 'longitude']);

        return view('map.index', [
            'routers' => $routers,
            'selected' => $routers->firstWhere('id', $request->integer('router_id')) ?? $routers->first(),
            'publicUrl' => route('public-map.show', self::publicToken($settings)),
        ]);
    }

    public function data(Router $router, NetworkMapData $map): JsonResponse
    {
        Gate::authorize('operate');
        $data = $map->build($router);
        $data['unplaced_list'] = PppoeCustomer::where('router_id', $router->id)
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))->orderBy('full_name')->get(['id', 'full_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name]);
        $data['customer_url'] = route('customers.show', '__ID__');

        return response()->json($data);
    }

    public function storeOdp(Request $request): JsonResponse
    {
        Gate::authorize('operate');
        $odp = Odp::create($this->validatedOdp($request, true));
        $this->audit->log('map.odp_created', $odp, ['name' => $odp->name]);

        return response()->json(['id' => $odp->id], 201);
    }

    public function updateOdp(Request $request, Odp $odp): JsonResponse
    {
        Gate::authorize('operate');
        $odp->update($this->validatedOdp($request, false));

        return response()->json(['ok' => true]);
    }

    public function destroyOdp(Odp $odp): JsonResponse
    {
        Gate::authorize('operate');
        PppoeCustomer::where('odp_id', $odp->id)->update(['odp_id' => null]);
        $odp->delete();
        $this->audit->log('map.odp_deleted', $odp, ['name' => $odp->name]);

        return response()->json(['ok' => true]);
    }

    /** Geser/tempatkan pelanggan di peta dan/atau pilih ODP-nya. */
    public function updateCustomer(Request $request, PppoeCustomer $customer): JsonResponse
    {
        Gate::authorize('operate');
        $d = $request->validate([
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'], 'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'odp_id' => ['sometimes', 'nullable', Rule::exists('odps', 'id')->where('router_id', $customer->router_id)],
        ]);
        $customer->update(array_filter([
            'latitude' => array_key_exists('lat', $d) ? $d['lat'] : false,
            'longitude' => array_key_exists('lng', $d) ? $d['lng'] : false,
            'odp_id' => array_key_exists('odp_id', $d) ? $d['odp_id'] : false,
        ], fn ($v) => $v !== false));

        return response()->json(['ok' => true]);
    }

    public function updateServer(Request $request, Router $router): JsonResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $router->update(['latitude' => $d['lat'], 'longitude' => $d['lng']]);

        return response()->json(['ok' => true]);
    }

    /** Ganti link peta publik (link lama langsung tidak berlaku). */
    public function regenerateToken(SnetSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $settings->put('pppoe', ['map_public_token' => Str::random(40)]);
        $this->audit->log('map.public_token_regenerated');

        return back()->with('status', 'Link peta publik diganti. Link lama tidak berlaku lagi.');
    }

    public static function publicToken(SnetSettings $settings): string
    {
        $token = $settings->get('pppoe', 'map_public_token');
        if (blank($token)) {
            $token = Str::random(40);
            $settings->put('pppoe', ['map_public_token' => $token]);
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function validatedOdp(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $d = $request->validate([
            'router_id' => [$creating ? 'required' : 'prohibited', 'exists:routers,id'],
            'name' => [$req, 'string', 'max:100'],
            'lat' => [$req, 'numeric', 'between:-90,90'], 'lng' => [$req, 'numeric', 'between:-180,180'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,256'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (isset($d['lat'])) {
            $d['latitude'] = $d['lat'];
        }
        if (isset($d['lng'])) {
            $d['longitude'] = $d['lng'];
        }
        unset($d['lat'], $d['lng']);

        return $d;
    }
}

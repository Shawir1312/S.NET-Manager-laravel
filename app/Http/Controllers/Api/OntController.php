<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchOntRequest;
use App\Models\OntDevice;
use App\Services\GenieACS\GenieACSService;
use App\Services\ONT\OntLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OntController extends Controller
{
    /** Di-resolve saat dipakai (bukan di konstruktor) agar server GenieACS pilihan middleware ikut terpakai. */
    private function lookup(): OntLookupService
    {
        return app(OntLookupService::class);
    }

    /** POST /api/ont/search */
    public function search(SearchOntRequest $request): JsonResponse
    {
        // "Tidak ditemukan" adalah hasil pencarian yang sah (HTTP 200, status = not_found),
        // bukan error. Error asli (GenieACS mati, dsb.) tetap memakai 4xx/5xx.
        return response()->json($this->lookup()->search($request->validated('serial_number')));
    }

    /** POST /api/ont/locate — SN ini terdaftar di server GenieACS mana (semua server aktif dicek). */
    public function locate(SearchOntRequest $request): JsonResponse
    {
        return response()->json(['found' => OntLookupService::locate($request->validated('serial_number'))]);
    }

    /** GET /api/ont/{device} */
    public function show(OntDevice $device): JsonResponse
    {
        Gate::authorize('view-devices');
        $r = $this->lookup()->inspect($device);

        return response()->json(['id' => $r['device']->id, 'info' => $r['info'], 'detection' => $r['detection']]);
    }

    /** GET /api/ont/{device}/parameters?prefix= */
    public function parameters(Request $request, OntDevice $device, GenieACSService $acs): JsonResponse
    {
        Gate::authorize('view-devices');
        $prefix = $request->validate(['prefix' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\-]+$/']])['prefix'] ?? null;

        $parameters = $acs->getDeviceParameters($device->genieacs_id, $prefix);

        return response()->json(['count' => count($parameters), 'parameters' => $parameters]);
    }

    /** GET /api/ont/{device}/capabilities */
    public function capabilities(OntDevice $device): JsonResponse
    {
        Gate::authorize('view-devices');
        $r = $this->lookup()->inspect($device);

        return response()->json([
            'capabilities' => $r['info']['capabilities'],
            'counts' => $r['info']['counts'],
            'detection' => $r['detection'],
        ]);
    }

    /** POST /api/ont/{device}/check-online */
    public function checkOnline(OntDevice $device): JsonResponse
    {
        Gate::authorize('view-devices');

        return response()->json($this->lookup()->checkReadiness($device));
    }

    /** POST /api/ont/{device}/refresh */
    public function refresh(OntDevice $device): JsonResponse
    {
        Gate::authorize('configure-ont');

        return response()->json($this->lookup()->refresh($device));
    }

    /** POST /api/ont/sync */
    public function sync(): JsonResponse
    {
        Gate::authorize('configure-ont');
        $r = $this->lookup()->syncAll();

        return response()->json($r + ['message' => "{$r['synced']} ONT disinkronkan dari GenieACS."]);
    }
}

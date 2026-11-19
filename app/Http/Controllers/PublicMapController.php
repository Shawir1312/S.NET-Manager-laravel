<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Network\NetworkMapData;
use App\Services\Settings\SnetSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Peta jaringan tanpa login, dilindungi link rahasia (token) yang bisa diganti admin. */
class PublicMapController extends Controller
{
    public function show(string $token, SnetSettings $settings): View
    {
        $this->check($token, $settings);

        return view('map.public', ['token' => $token, 'routers' => Router::where('status', 'active')->orderBy('name')->get(['id', 'name'])]);
    }

    public function data(string $token, Router $router, SnetSettings $settings, NetworkMapData $map): JsonResponse
    {
        $this->check($token, $settings);
        abort_unless($router->status === 'active', 404);

        return response()->json($map->build($router, public: true));
    }

    private function check(string $token, SnetSettings $settings): void
    {
        $expected = (string) $settings->get('pppoe', 'map_public_token');
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);
    }
}

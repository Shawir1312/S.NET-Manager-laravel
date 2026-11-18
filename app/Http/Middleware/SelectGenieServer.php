<?php

namespace App\Http\Middleware;

use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Services\GenieACS\GenieACSService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pilih server GenieACS untuk request ini: milik ONT di route ({device}),
 * atau `genie_server_id` dari form/query (mis. pencarian SN pelanggan).
 */
class SelectGenieServer
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->route('device');
        $setting = match (true) {
            $device instanceof OntDevice && $device->genie_acs_setting_id !== null => $device->genieAcsSetting,
            $request->filled('genie_server_id') => GenieAcsSetting::find($request->integer('genie_server_id')),
            default => null,
        };

        if ($setting) {
            GenieACSService::use($setting);
        }

        return $next($request);
    }
}

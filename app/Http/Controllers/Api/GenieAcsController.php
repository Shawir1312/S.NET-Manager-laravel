<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenieAcsSetting;
use App\Services\Audit\AuditLogger;
use App\Services\GenieACS\GenieACSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class GenieAcsController extends Controller
{
    /** POST /api/genieacs/test-connection : menguji pengaturan yang TERSIMPAN. */
    public function testConnection(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manage-settings');

        $setting = $request->filled('server_id') ? GenieAcsSetting::find($request->integer('server_id')) : GenieAcsSetting::active();
        if (! $setting) {
            return response()->json(['ok' => false, 'message' => 'Simpan pengaturan GenieACS terlebih dahulu.'], 422);
        }

        $result = (new GenieACSService($setting))->testConnection();

        $setting->forceFill([
            'last_test_status' => $result['ok'] ? 'ok' : 'failed',
            'last_test_message' => Str::limit($result['message'], 250, ''),
            'last_test_at' => now(),
        ])->save();

        $audit->log('genieacs.test_connection', $setting, ['ok' => $result['ok'], 'latency_ms' => $result['latency_ms']]);

        return response()->json($result + ['tested_at' => $setting->last_test_at->toIso8601String()]);
    }
}

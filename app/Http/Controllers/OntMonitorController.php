<?php

namespace App\Http\Controllers;

use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Models\OntRemote;
use App\Services\GenieACS\GenieACSService;
use App\Services\Network\OntRemoteService;
use App\Services\ONT\OntLookupService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/** Monitor ONT S.NET: semua ONT dari semua server GenieACS + nama pelanggan + remote & reboot. */
class OntMonitorController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('operate');
        $servers = GenieAcsSetting::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $selServer = $servers->firstWhere('id', $request->integer('server_id')) ?? $servers->first();
        $threshold = now()->subSeconds((int) config('ontconfig.online_threshold_seconds'));

        $devices = $selServer ? OntDevice::query()
            ->leftJoin('pppoe_customers as c', 'c.ont_sn', '=', 'ont_devices.serial_number')
            ->select('ont_devices.*', 'c.id as customer_id', 'c.full_name as customer_name', 'c.pppoe_username as customer_username')
            ->where('ont_devices.genie_acs_setting_id', $selServer->id)
            ->orderByDesc('last_inform_at')->get() : collect();

        return view('ont-monitor.index', [
            'devices' => $devices, 'servers' => $servers, 'selServer' => $selServer, 'threshold' => $threshold,
            'remotes' => OntRemote::where('is_active', true)->where('expires_at', '>', now())->get()->keyBy('ont_sn'),
            'remoteService' => app(OntRemoteService::class),
        ]);
    }

    /** Sinkronkan daftar ONT dari setiap server GenieACS aktif. */
    public function sync(): RedirectResponse
    {
        Gate::authorize('configure-ont');
        $msgs = [];
        foreach (GenieAcsSetting::where('is_active', true)->get() as $server) {
            GenieACSService::use($server);
            try {
                $msgs[] = "{$server->name}: ".app(OntLookupService::class)->syncAll()['synced'].' ONT';
            } catch (Throwable $e) {
                $msgs[] = "{$server->name}: gagal ({$e->getMessage()})";
            }
        }

        return back()->with('status', 'Sinkronisasi selesai. '.implode(' · ', $msgs));
    }

    public function remote(Request $request, OntDevice $device, OntRemoteService $remotes): RedirectResponse|JsonResponse
    {
        Gate::authorize('configure-ont');
        $port = (int) ($request->validate(['port' => ['nullable', 'in:80,443,8080']])['port'] ?? 80);
        try {
            $r = $remotes->start($device, $port);
        } catch (RuntimeException $e) {
            return $request->wantsJson() ? response()->json(['success' => false, 'error' => $e->getMessage()]) : back()->withErrors(['remote' => $e->getMessage()]);
        }

        return $request->wantsJson()
            ? response()->json(['success' => true, 'remote_url' => $remotes->url($r), 'remaining_seconds' => max(0, now()->diffInSeconds($r->expires_at, false)), 'close_url' => route('ont-monitor.remote.close', $r)])
            : back()->with('status', "Remote {$device->serial_number} aktif sampai ".$r->expires_at->format('H:i').': '.$remotes->url($r));
    }

    public function closeRemote(Request $request, OntRemote $remote, OntRemoteService $remotes): RedirectResponse|JsonResponse
    {
        Gate::authorize('configure-ont');
        $remotes->close($remote);

        return $request->wantsJson() ? response()->json(['success' => true]) : back()->with('status', 'Akses remote ditutup.');
    }

    public function closeAllRemotes(OntRemoteService $remotes): JsonResponse
    {
        Gate::authorize('configure-ont');
        $active = OntRemote::where('is_active', true)->get();
        $active->each(fn (OntRemote $r) => $remotes->close($r));

        return response()->json(['success' => true, 'message' => "{$active->count()} sesi remote ONT ditutup."]);
    }

    /** Reboot ONT (hanya mode produksi; di mode simulasi tidak ada perintah yang dikirim). */
    public function reboot(OntDevice $device): RedirectResponse
    {
        Gate::authorize('configure-ont');
        if (! ProvisioningService::isProduction()) {
            return back()->withErrors(['reboot' => 'Mode SIMULASI: perintah reboot tidak dikirim ke ONT.']);
        }
        $r = GenieACSService::forDevice($device)->createTask($device->genieacs_id, ['name' => 'reboot']);

        return back()->with('status', "Perintah reboot dikirim ke {$device->serial_number}".($r['completed'] ? '.' : ' (antre, dijalankan saat ONT inform berikutnya).'));
    }
}

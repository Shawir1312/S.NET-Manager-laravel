<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGenieAcsSettingRequest;
use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GenieAcsSettingController extends Controller
{
    /** S.NET: banyak server. `?server=ID` pilih server, `?server=new` tambah server baru. */
    public function edit(Request $request): View
    {
        Gate::authorize('manage-settings');

        $wanted = $request->query('server');
        $setting = ($wanted && $wanted !== 'new' ? GenieAcsSetting::find((int) $wanted) : null)
            ?? ($wanted === 'new' ? null : GenieAcsSetting::active())
            ?? new GenieAcsSetting(['name' => 'GenieACS',
                'nbi_url' => config('ontconfig.genieacs.nbi_url'),
                'connect_timeout' => 5, 'request_timeout' => 30, 'connection_request_timeout' => 10, 'verify_tls' => true,
            ]);

        return view('settings.genieacs', [
            'setting' => $setting,
            'servers' => GenieAcsSetting::orderBy('name')->get(['id', 'name', 'nbi_url', 'is_active', 'last_test_status']),
            // Hanya status "ada / tidak ada", nilai credential tidak pernah dikirim ke view.
            'hasCredentials' => $setting->exists && $setting->hasCredentials(),
        ]);
    }

    public function update(UpdateGenieAcsSettingRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $setting = $request->filled('server_id')
            ? GenieAcsSetting::findOrFail($data['server_id'])
            : ($request->boolean('new_server') ? new GenieAcsSetting : (GenieAcsSetting::active() ?? new GenieAcsSetting(['is_active' => true])));
        $before = $setting->only(['nbi_url', 'cwmp_url', 'connect_timeout', 'request_timeout', 'connection_request_timeout', 'verify_tls']);

        $setting->fill([
            'name' => $data['name'] ?? $setting->name ?? 'GenieACS',
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : ($setting->is_active ?? true),
            'nbi_url' => rtrim($data['nbi_url'], '/'),
            'cwmp_url' => $data['cwmp_url'] ?? null,
            'connect_timeout' => $data['connect_timeout'],
            'request_timeout' => $data['request_timeout'],
            'connection_request_timeout' => $data['connection_request_timeout'],
            'verify_tls' => $request->boolean('verify_tls'),
            'updated_by' => $request->user()->id,
        ]);

        $credentialsChanged = false;
        if ($request->boolean('clear_credentials')) {
            $setting->username = null;
            $setting->password = null;
            $credentialsChanged = true;
        } else {
            if ($request->filled('username')) {
                $setting->username = $data['username'];
                $credentialsChanged = true;
            }
            if ($request->filled('password')) {
                $setting->password = $data['password'];
                $credentialsChanged = true;
            }
        }

        // URL/credential berubah = hasil tes lama tidak berlaku lagi.
        if ($setting->isDirty(['nbi_url', 'username', 'password', 'verify_tls'])) {
            $setting->last_test_status = null;
            $setting->last_test_message = null;
            $setting->last_test_at = null;
        }

        $setting->save();

        $audit->log('genieacs.settings_updated', $setting, [
            'before' => $before,
            'after' => $setting->only(array_keys($before)),
            'credentials_changed' => $credentialsChanged,
        ]);

        return redirect()->route('settings.genieacs.edit', $request->filled('server_id') || $request->boolean('new_server') ? ['server' => $setting->id] : [])->with('status', 'Pengaturan GenieACS disimpan.');
    }

    /** Hapus server GenieACS; ONT & router yang memakainya dilepas (kembali ke server aktif default). */
    public function destroy(GenieAcsSetting $server, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-settings');
        if (GenieAcsSetting::count() <= 1) {
            return back()->withErrors(['server' => 'Minimal harus ada satu server GenieACS.']);
        }
        DB::transaction(function () use ($server) {
            OntDevice::where('genie_acs_setting_id', $server->id)->update(['genie_acs_setting_id' => null]);
            Router::where('genie_acs_setting_id', $server->id)->update(['genie_acs_setting_id' => null]);
            $server->delete();
        });
        $audit->log('genieacs.server_deleted', null, ['name' => $server->name]);

        return redirect()->route('settings.genieacs.edit')->with('status', "Server {$server->name} dihapus.");
    }
}

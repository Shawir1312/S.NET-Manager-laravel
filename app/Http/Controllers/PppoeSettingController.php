<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SnetSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Pengaturan PPPoE: perusahaan, isolir, payment gateway, default akun/WiFi pelanggan baru. */
class PppoeSettingController extends Controller
{
    /** Kunci => aturan validasi. Pengaturan slot/VLAN ONT lama diganti Template PPPoE & Hotspot. */
    private const FIELDS = [
        'company_name' => ['nullable', 'string', 'max:100'],
        'company_phone' => ['nullable', 'string', 'max:30'],
        'company_address' => ['nullable', 'string', 'max:255'],
        'isolir_grace_days' => ['required', 'integer', 'between:0,31'],
        'isolir_profile' => ['required', 'string', 'max:64'],
        'isolir_redirect_url' => ['nullable', 'url', 'max:255'],
        'ont_username_suffix' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9@._\-]*$/'],
        'ont_wifi1_prefix' => ['nullable', 'string', 'max:20'],
        'active_payment_gateway' => ['nullable', 'in:none,midtrans,tripay,both'],
        'midtrans_mode' => ['nullable', 'in:sandbox,production'],
        'midtrans_client_key' => ['nullable', 'string', 'max:255'],
        'midtrans_server_key' => ['nullable', 'string', 'max:255'],
        'tripay_mode' => ['nullable', 'in:sandbox,production'],
        'tripay_merchant_code' => ['nullable', 'string', 'max:100'],
        'tripay_api_key' => ['nullable', 'string', 'max:255'],
        'tripay_private_key' => ['nullable', 'string', 'max:255'],
    ];

    public function edit(SnetSettings $settings): View
    {
        Gate::authorize('manage-settings');

        return view('pppoe.settings', [
            's' => $settings->all('pppoe'),
            'templates' => ConfigurationTemplate::orderBy('name')->get(['id', 'name', 'service_type', 'is_default']),
        ]);
    }

    public function update(Request $request, SnetSettings $settings, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $data = $request->validate(self::FIELDS);
        // Kunci rahasia kosong = tidak diubah (nilainya tidak pernah dikirim balik ke browser).
        foreach (SnetSettings::SECRET_KEYS as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]);
            }
        }
        // Template default PPPoE & Hotspot dipakai mesin provisioning ont-autoconfig.
        foreach (['pppoe' => 'default_pppoe_template_id', 'bridge' => 'default_hotspot_template_id'] as $type => $field) {
            $id = $request->integer($field);
            if ($id && ConfigurationTemplate::whereKey($id)->where('service_type', $type)->exists()) {
                ConfigurationTemplate::where('service_type', $type)->update(['is_default' => false]);
                ConfigurationTemplate::whereKey($id)->update(['is_default' => true]);
            }
        }
        $settings->put('pppoe', $data);
        $audit->log('settings.pppoe_updated', null, ['keys' => array_keys($data)]);

        return back()->with('status', 'Pengaturan PPPoE disimpan.');
    }
}

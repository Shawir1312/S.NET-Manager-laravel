<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\ConfigurationTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\ONT\Mappers\WANMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('configure-ont');

        return view('templates.index', ['templates' => ConfigurationTemplate::with('creator:id,name')->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('manage-templates');

        return view('templates.form', ['template' => new ConfigurationTemplate(['service_type' => ServiceType::Bridge, 'payload' => []])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-templates');
        $t = ConfigurationTemplate::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->syncDefault($t);
        $this->audit->log('template.created', $t, ['name' => $t->name]);

        return redirect()->route('templates.index')->with('status', "Template \"{$t->name}\" dibuat.");
    }

    public function edit(ConfigurationTemplate $template): View
    {
        Gate::authorize('manage-templates');

        return view('templates.form', ['template' => $template]);
    }

    public function update(Request $request, ConfigurationTemplate $template): RedirectResponse
    {
        Gate::authorize('manage-templates');
        $template->update($this->validated($request, $template));
        $this->syncDefault($template);
        $this->audit->log('template.updated', $template, ['name' => $template->name]);

        return redirect()->route('templates.index')->with('status', "Template \"{$template->name}\" diperbarui.");
    }

    public function destroy(ConfigurationTemplate $template): RedirectResponse
    {
        Gate::authorize('manage-templates');
        $template->delete();
        $this->audit->log('template.deleted', null, ['name' => $template->name]);

        return redirect()->route('templates.index')->with('status', "Template \"{$template->name}\" dihapus.");
    }

    public function copy(ConfigurationTemplate $template): RedirectResponse
    {
        Gate::authorize('manage-templates');
        $name = $template->name.' (salinan)';
        for ($n = 2; ConfigurationTemplate::where('name', $name)->exists(); $n++) {
            $name = "{$template->name} (salinan {$n})";
        }
        $copy = $template->replicate(['is_default']);
        $copy->fill(['name' => $name, 'is_default' => false, 'created_by' => auth()->id()])->save();
        $this->audit->log('template.copied', $copy, ['from' => $template->name]);

        return redirect()->route('templates.edit', $copy)->with('status', 'Template disalin.');
    }

    public function makeDefault(ConfigurationTemplate $template): RedirectResponse
    {
        Gate::authorize('manage-templates');
        $template->update(['is_default' => true]);
        $this->syncDefault($template);
        $this->audit->log('template.default', $template, ['name' => $template->name]);

        return back()->with('status', "\"{$template->name}\" dijadikan template default.");
    }

    private function syncDefault(ConfigurationTemplate $t): void
    {
        if ($t->is_default) {
            // Satu default per jenis layanan: satu untuk PPPoE, satu untuk Hotspot/Bridge.
            DB::table('configuration_templates')->where('id', '!=', $t->id)
                ->where('service_type', $t->service_type->value)->update(['is_default' => false]);
        }
    }

    private function validated(Request $request, ?ConfigurationTemplate $template = null): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('configuration_templates')->ignore($template?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'service_type' => ['required', 'in:bridge,pppoe'],
            'slot' => ['required', 'in:ip,ppp'],
            'wan_name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\- .]+$/'],
            'vlan_id' => ['required', 'integer', 'between:1,4094'],
            'vlan_priority' => ['nullable', 'integer', 'between:0,7'],
            'service_list' => ['required', 'in:'.implode(',', WANMapper::SERVICE_LISTS)],
            'nat_enabled' => ['sometimes', 'boolean'],
            'lan' => ['array'], 'lan.*' => ['integer', 'between:1,8'],
            'wlan' => ['array'], 'wlan.*' => ['integer', 'between:1,8'],
            'is_default' => ['sometimes', 'boolean'],
            'ssid_names' => ['array'], 'ssid_names.*' => ['nullable', 'string', 'max:32'],
            'wifi_security' => ['nullable', 'in:keep,open,password'],
            'wifi_key' => ['nullable', 'string', 'min:8', 'max:63'],
            // Seksi Hotspot di dalam template PPPoE (PPPoE + Hotspot dalam satu template).
            'hs_enabled' => ['sometimes', 'boolean'],
            'hs_vlan_id' => ['nullable', 'required_if:hs_enabled,1', 'integer', 'between:1,4094'],
            'hs_vlan_priority' => ['nullable', 'integer', 'between:0,7'],
            'hs_service_list' => ['nullable', 'in:'.implode(',', WANMapper::SERVICE_LISTS)],
            'hs_wan_name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\- .]+$/'],
            'hs_lan' => ['array'], 'hs_lan.*' => ['integer', 'between:1,8'],
            'hs_wlan' => ['array'], 'hs_wlan.*' => ['integer', 'between:1,8'],
            'hs_ssid_names' => ['array'], 'hs_ssid_names.*' => ['nullable', 'string', 'max:32'],
            'hs_wifi_security' => ['nullable', 'in:keep,open,password'],
            'hs_wifi_key' => ['nullable', 'string', 'min:8', 'max:63'],
        ]);
        // Keamanan WiFi hotspot: password disimpan TERENKRIPSI (bukan teks biasa); kosong saat edit = tidak diubah.
        $security = $d['service_type'] === 'bridge' ? ($d['wifi_security'] ?? 'keep') : 'keep';
        $keyEnc = filled($d['wifi_key'] ?? null) ? Crypt::encryptString($d['wifi_key']) : ($template?->payload['wifi']['key_enc'] ?? null);
        if ($security === 'password' && blank($keyEnc)) {
            throw ValidationException::withMessages(['wifi_key' => 'Isi password WiFi hotspot (minimal 8 karakter).']);
        }
        $wlan = array_map('intval', $d['wlan'] ?? []);
        $lan = array_map('intval', $d['lan'] ?? []);
        $ssids = self::fixedSsids($wlan, $d['ssid_names'] ?? []);
        $pppoe = $d['service_type'] === 'pppoe';
        $hotspot = $pppoe && $request->boolean('hs_enabled') ? $this->hotspotSection($d, $lan, $wlan, $template) : null;

        return [
            'name' => $d['name'],
            'description' => $d['description'] ?? null,
            'service_type' => $d['service_type'],
            'is_default' => $request->boolean('is_default'),
            // Password PPPoE/WiFi TIDAK PERNAH disimpan di template (juga dibuang otomatis oleh model).
            'payload' => [
                'service_type' => $d['service_type'],
                'wan' => [
                    'slot' => $d['slot'], 'name' => $d['wan_name'] ?? null, 'vlan_id' => (int) $d['vlan_id'],
                    'vlan_priority' => isset($d['vlan_priority']) ? (int) $d['vlan_priority'] : null,
                    'service_list' => $pppoe ? 'INTERNET' : $d['service_list'],
                    'nat_enabled' => $pppoe ? $request->boolean('nat_enabled', true) : null,
                ],
                'binding' => ['lan' => $lan, 'wlan' => $wlan],
                'ssids' => $ssids,
                'wifi' => ['security' => $security, 'key_enc' => $security === 'password' ? $keyEnc : null],
                // PPPoE + Hotspot dalam satu template: WAN hotspot ikut dipasang saat template PPPoE ini dipakai.
                'hotspot' => $hotspot,
            ],
        ];
    }

    /**
     * Nama SSID tetap per WLAN yang di-bind; boleh memakai {nama} = nama depan pelanggan.
     *
     * @param  list<int>  $wlan
     * @param  array<int|string, ?string>  $names
     * @return list<array{index: int, ssid_name: string}>
     */
    private static function fixedSsids(array $wlan, array $names): array
    {
        $ssids = [];
        foreach ($wlan as $n) {
            if (filled($names[$n] ?? null)) {
                $ssids[] = ['index' => $n, 'ssid_name' => $names[$n]];
            }
        }

        return $ssids;
    }

    /**
     * Seksi hotspot template PPPoE. Port LAN/SSID dan VLAN-nya tidak boleh sama dengan PPPoE (satu interface hanya bisa di-bind ke satu WAN).
     *
     * @param  list<int>  $lan
     * @param  list<int>  $wlan
     * @return array<string, mixed>
     */
    private function hotspotSection(array $d, array $lan, array $wlan, ?ConfigurationTemplate $template): array
    {
        $hsLan = array_map('intval', $d['hs_lan'] ?? []);
        $hsWlan = array_map('intval', $d['hs_wlan'] ?? []);
        $errors = [];
        if ($hsLan === [] && $hsWlan === []) {
            $errors['hs_wlan'] = 'Pilih minimal satu LAN/SSID untuk hotspot.';
        }
        if ($both = array_intersect($lan, $hsLan)) {
            $errors['hs_lan'] = 'LAN '.implode(', ', $both).' sudah dipakai PPPoE. Satu port hanya untuk satu layanan.';
        }
        if ($both = array_intersect($wlan, $hsWlan)) {
            $errors['hs_wlan'] = 'SSID '.implode(', ', $both).' sudah dipakai PPPoE. Pilih SSID lain untuk hotspot (mis. SSID 2).';
        }
        if ((int) $d['hs_vlan_id'] === (int) $d['vlan_id']) {
            $errors['hs_vlan_id'] = 'VLAN hotspot harus beda dengan VLAN PPPoE.';
        }
        $security = $d['hs_wifi_security'] ?? 'keep';
        $keyEnc = filled($d['hs_wifi_key'] ?? null) ? Crypt::encryptString($d['hs_wifi_key']) : ($template?->payload['hotspot']['wifi']['key_enc'] ?? null);
        if ($security === 'password' && blank($keyEnc)) {
            $errors['hs_wifi_key'] = 'Isi password WiFi hotspot (minimal 8 karakter).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'wan' => [
                'slot' => 'ip', 'name' => $d['hs_wan_name'] ?? null, 'vlan_id' => (int) $d['hs_vlan_id'],
                'vlan_priority' => isset($d['hs_vlan_priority']) ? (int) $d['hs_vlan_priority'] : null,
                'service_list' => $d['hs_service_list'] ?? 'INTERNET',
            ],
            'binding' => ['lan' => $hsLan, 'wlan' => $hsWlan],
            'ssids' => self::fixedSsids($hsWlan, $d['hs_ssid_names'] ?? []),
            'wifi' => ['security' => $security, 'key_enc' => $security === 'password' ? $keyEnc : null],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\ConfigurationTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\ONT\Mappers\WANMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

        return view('templates.form', compact('template'));
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
        ]);
        $wlan = array_map('intval', $d['wlan'] ?? []);
        // Nama SSID tetap per WLAN yang di-bind; boleh memakai {nama} = nama depan pelanggan.
        $ssids = [];
        foreach ($wlan as $n) {
            if (filled($d['ssid_names'][$n] ?? null)) {
                $ssids[] = ['index' => $n, 'ssid_name' => $d['ssid_names'][$n]];
            }
        }
        $pppoe = $d['service_type'] === 'pppoe';

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
                'binding' => ['lan' => array_map('intval', $d['lan'] ?? []), 'wlan' => $wlan],
                'ssids' => $ssids,
            ],
        ];
    }
}

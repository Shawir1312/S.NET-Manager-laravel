<?php

namespace App\Http\Controllers;

use App\Enums\BindingMethod;
use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use App\Models\OntModel;
use App\Models\OntParameterMapping;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceModelController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('manage-models');

        return view('models.index', ['models' => OntModel::with('capability')->withCount([
            'parameterMappings', 'parameterMappings as verified_count' => fn ($q) => $q->where('verified', true), 'devices',
        ])->orderBy('vendor')->orderBy('model_name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('manage-models');

        return view('models.form', ['model' => new OntModel(['firmware_pattern' => '*', 'status' => ModelSupportStatus::Testing])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-models');
        $model = OntModel::create($this->validatedModel($request));
        $model->capability()->create($this->validatedCapability($request));
        $this->audit->log('model.created', $model, $model->only(['vendor', 'product_class']));

        return redirect()->route('models.edit', $model)->with('status', 'Model dibuat. Tambahkan mapping parameter dari ONT asli.');
    }

    public function edit(OntModel $model): View
    {
        Gate::authorize('manage-models');
        $model->load(['capability', 'parameterMappings' => fn ($q) => $q->orderBy('key')]);

        return view('models.form', compact('model'));
    }

    public function update(Request $request, OntModel $model): RedirectResponse
    {
        Gate::authorize('manage-models');
        $before = $model->only(['status', 'firmware_pattern']);
        $model->update($this->validatedModel($request, $model));
        $model->capability()->updateOrCreate([], $this->validatedCapability($request, $model->capability?->extra ?? []));
        $this->audit->log('model.updated', $model, ['before' => $before, 'after' => $model->only(['status', 'firmware_pattern'])]);

        return back()->with('status', 'Model diperbarui.');
    }

    public function saveMapping(Request $request, OntModel $model): RedirectResponse
    {
        Gate::authorize('manage-models');
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/'],
            'path_template' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\-{}]+$/'],
            'value_type' => ['required', 'in:xsd:string,xsd:boolean,xsd:unsignedInt,xsd:int,xsd:dateTime'],
            'verified' => ['sometimes', 'boolean'],
            'source' => ['nullable', 'string', 'max:191'],
        ]);
        $verified = $request->boolean('verified');
        $mapping = $model->parameterMappings()->updateOrCreate(['key' => $d['key']], [
            'path_template' => $d['path_template'], 'value_type' => $d['value_type'], 'verified' => $verified,
            'verification_level' => $verified ? ($model->parameterMappings()->where('key', $d['key'])->value('verification_level') ?? 'manual') : null,
            'verified_at' => $verified ? now() : null, 'source' => $d['source'] ?? 'Diubah manual oleh '.$request->user()->name,
        ]);
        $this->audit->log('model.mapping_saved', $model, $mapping->only(['key', 'path_template', 'verified']));

        return back()->with('status', "Mapping {$d['key']} disimpan.");
    }

    /** Simpan semua centang "verified" sekaligus; hanya baris yang berubah yang disentuh (level path/write tetap). */
    public function saveVerified(Request $request, OntModel $model): RedirectResponse
    {
        Gate::authorize('manage-models');
        $ids = array_map('intval', $request->validate(['verified_ids' => ['array'], 'verified_ids.*' => ['integer']])['verified_ids'] ?? []);
        $changed = [];
        foreach ($model->parameterMappings()->get() as $mapping) {
            $verified = in_array($mapping->id, $ids, true);
            if ($verified === (bool) $mapping->verified) {
                continue;
            }
            $mapping->update($verified
                ? ['verified' => true, 'verification_level' => 'manual', 'verified_at' => now(), 'source' => 'Ditandai verified manual oleh '.$request->user()->name]
                : ['verified' => false, 'verification_level' => null, 'verified_at' => null]);
            $changed[] = ($verified ? '+' : '-').$mapping->key;
        }
        $this->audit->log('model.mappings_verified', $model, ['changed' => $changed]);

        return back()->with('status', $changed === [] ? 'Tidak ada perubahan centang.' : count($changed).' mapping diperbarui.');
    }

    public function deleteMapping(OntModel $model, OntParameterMapping $mapping): RedirectResponse
    {
        Gate::authorize('manage-models');
        abort_unless($mapping->ont_model_id === $model->id, 404);
        $mapping->delete();
        $this->audit->log('model.mapping_deleted', $model, ['key' => $mapping->key]);

        return back()->with('status', "Mapping {$mapping->key} dihapus.");
    }

    private function validatedModel(Request $request, ?OntModel $model = null): array
    {
        $d = $request->validate([
            'vendor' => ['required', Rule::enum(Vendor::class)],
            'model_name' => ['required', 'string', 'max:100'],
            'product_class' => ['required', 'string', 'max:100'],
            'firmware_pattern' => ['required', 'string', 'max:191', function ($attr, $value, $fail) {
                if ($value !== '*' && @preg_match('/'.$value.'/', '') === false) {
                    $fail('Pola firmware bukan regex yang valid.');
                }
            }],
            'status' => ['required', Rule::enum(ModelSupportStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $exists = OntModel::where(['vendor' => $d['vendor'], 'product_class' => $d['product_class'], 'firmware_pattern' => $d['firmware_pattern']])
            ->when($model, fn ($q) => $q->where('id', '!=', $model->id))->exists();
        abort_if($exists, 422, 'Kombinasi vendor + product class + pola firmware sudah ada.');

        return $d;
    }

    /**
     * @param  array<string, mixed>  $currentExtra  pengaturan tambahan yang sudah ada (mis. ssid_band) TIDAK boleh hilang saat form disimpan
     */
    private function validatedCapability(Request $request, array $currentExtra = []): array
    {
        $d = $request->validate([
            'max_wan_ip' => ['nullable', 'integer', 'between:1,16'], 'max_wan_ppp' => ['nullable', 'integer', 'between:1,16'],
            'lan_ports' => ['nullable', 'integer', 'between:0,8'], 'ssid_count' => ['nullable', 'integer', 'between:0,16'],
            'binding_method' => ['required', Rule::enum(BindingMethod::class)],
            'vlan_min' => ['required', 'integer', 'between:1,4094'], 'vlan_max' => ['required', 'integer', 'between:1,4094', 'gte:vlan_min'],
            'pppoe_slot' => ['required', 'in:ip,ppp'],
            'new_wan_strategy' => ['nullable', 'in:new_wcd,existing_wcd'],
        ]);

        return [
            'max_wan_ip' => $d['max_wan_ip'] ?? null, 'max_wan_ppp' => $d['max_wan_ppp'] ?? null,
            'lan_ports' => $d['lan_ports'] ?? null, 'ssid_count' => $d['ssid_count'] ?? null,
            'has_5g' => $request->has('has_5g') ? $request->boolean('has_5g') : null,
            'supports_bridge' => $request->boolean('supports_bridge'), 'supports_pppoe' => $request->boolean('supports_pppoe'),
            'binding_method' => $d['binding_method'], 'vlan_min' => $d['vlan_min'], 'vlan_max' => $d['vlan_max'],
            'extra' => array_merge($currentExtra, [
                'pppoe_slot' => $d['pppoe_slot'],
                'wan_name_auto' => $request->boolean('wan_name_auto'),
                'new_wan_strategy' => $d['new_wan_strategy'] ?? ($currentExtra['new_wan_strategy'] ?? 'new_wcd'),
            ]),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\ConfigurationTemplate;
use App\Models\GenieAcsSetting;
use App\Models\OntDevice;
use App\Services\ONT\HotspotOntSetup;
use App\Services\Provisioning\ProvisioningException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Setting ONT Hotspot cepat: scan/isi SN → cek online → isi nama → terapkan template hotspot. */
class HotspotOntController extends Controller
{
    public function __construct(private readonly HotspotOntSetup $setup) {}

    public function index(): View
    {
        Gate::authorize('configure-ont');
        $templates = ConfigurationTemplate::where('service_type', ServiceType::Bridge->value)->orderByDesc('is_default')->orderBy('name')->get();

        return view('ont-hotspot.index', [
            'templates' => $templates->map(fn (ConfigurationTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'is_default' => $t->is_default, 'summary' => $this->setup->summary($t)]),
            'servers' => GenieAcsSetting::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        Gate::authorize('configure-ont');
        $d = $request->validate([
            'device_id' => ['required', 'integer', 'exists:ont_devices,id'],
            'template_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:32', 'regex:/^[\pL\pN _.\-@#&()+]+$/u'],
        ], ['name.regex' => 'Nama hanya boleh huruf, angka, spasi dan tanda . - _ @ # & ( ) +']);

        try {
            $template = $this->setup->template($d['template_id'] ?? null);
            $job = $this->setup->apply(OntDevice::findOrFail($d['device_id']), $template, $d['name'], $request->user());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus);
        }

        return response()->json(['id' => $job->uuid, 'status' => $job->fresh()->status->value, 'is_simulation' => $job->is_simulation,
            'status_url' => route('api.configuration.status', $job->uuid), 'history_url' => route('history.show', $job->uuid)], 202);
    }
}

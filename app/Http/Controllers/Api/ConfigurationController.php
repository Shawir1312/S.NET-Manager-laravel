<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfigureOntRequest;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\OntDevice;
use App\Services\GenieACS\GenieACSService;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\ONTDetector;
use App\Services\ONT\ONTManager;
use App\Services\ONT\ParameterDiscoveryService;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConfigurationController extends Controller
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    /** GET /api/ont/{device}/config-context : data untuk wizard (WAN, SSID, LAN, kemampuan). */
    public function context(OntDevice $device, GenieACSService $acs, ONTDetector $detector, ONTManager $manager): JsonResponse
    {
        Gate::authorize('configure-ont');
        $tree = $acs->getDeviceDetails($device->genieacs_id);
        $ctx = DeviceContext::build($device, $tree, $detector->detect($tree));
        $driver = $manager->driverFor($ctx->detection);
        $info = $driver->getDeviceInfo($ctx);

        return response()->json([
            'device_id' => $device->id,
            'detection' => $ctx->detection->toArray(),
            'capabilities' => $driver->getCapabilities($ctx),
            'wans' => $driver->getWANConnections($ctx),
            'ssids' => $info['ssids'],
            'lan_count' => $info['counts']['lan'],
            'mode' => ProvisioningService::isProduction() ? 'production' : 'simulation',
        ]);
    }

    /** POST /api/ont/{device}/discover {slot: ip|ppp} : temukan parameter WAN lewat objek sementara. */
    public function discover(Request $request, OntDevice $device, ParameterDiscoveryService $discovery): JsonResponse
    {
        Gate::authorize('configure-ont');
        $slot = $request->validate(['slot' => ['required', 'in:ip,ppp']])['slot'];

        try {
            return response()->json($discovery->discoverWan($device, $slot));
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus);
        }
    }

    /** POST /api/ont/{device}/preview */
    public function preview(ConfigureOntRequest $request, OntDevice $device): JsonResponse
    {
        return response()->json($this->provisioning->preview($device, $request->validated()));
    }

    /** POST /api/ont/configure */
    public function configure(ConfigureOntRequest $request): JsonResponse
    {
        $device = OntDevice::findOrFail($request->validated('device_id'));
        try {
            $job = $this->provisioning->start($device, $request->validated(), $request->user(), $request->validated('template_id'));
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus);
        }

        return response()->json(['id' => $job->uuid, 'status' => $job->fresh()->status->value, 'is_simulation' => $job->is_simulation], 202);
    }

    /** GET /api/ont/{device}/jobs?after_id= — job baru di ONT ini (untuk pop-up progres, mis. hotspot yang diantrekan). */
    public function deviceJobs(Request $request, OntDevice $device): JsonResponse
    {
        $jobs = ConfigurationJob::where('ont_device_id', $device->id)->where('id', '>', $request->integer('after_id'))
            ->orderBy('id')->limit(5)->get()->filter(fn (ConfigurationJob $job) => $request->user()->can('view', $job));

        return response()->json($jobs->map(fn (ConfigurationJob $job) => [
            'label' => $job->service_type->label().' · '.$device->serial_number,
            'status_url' => route('api.configuration.status', $job->uuid),
            'history_url' => route('history.show', $job->uuid),
        ])->values());
    }

    /** GET /api/configuration/{job}/status */
    public function status(ConfigurationJob $job): JsonResponse
    {
        Gate::authorize('view', $job);
        $job->load('histories');
        $verify = $job->histories->where('event', 'verify')->last();

        return response()->json([
            'id' => $job->uuid,
            'status' => $job->status->value,
            'label' => $job->status->label(),
            'badge' => $job->status->badge(),
            'final' => $job->status->isFinal(),
            'is_simulation' => $job->is_simulation,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'timeline' => $job->histories->map(fn ($h) => [
                'event' => $h->event, 'status' => $h->status, 'message' => $h->message, 'at' => $h->created_at?->toIso8601String(),
            ])->values(),
            'verification' => $verify?->meta['results'] ?? null,
            'can_rollback' => $job->status->isFinal()
                && ($job->is_simulation ? $job->backups()->exists() : $job->backups()->where('applied', true)->exists())
                && ! $job->histories->contains('event', 'rollback') && request()->user()->can('rollback', $job),
        ]);
    }

    /** GET /api/configuration/{job}/history */
    public function history(ConfigurationJob $job): JsonResponse
    {
        Gate::authorize('view', $job);
        $job->load(['histories', 'backups', 'device', 'user:id,name', 'wanConfigurations.bindings', 'ssidConfigurations']);

        return response()->json([
            'id' => $job->uuid,
            'device' => ['serial_number' => $job->device->serial_number, 'vendor' => $job->device->vendor?->label(), 'model' => $job->device->product_class],
            'technician' => $job->user?->name,
            'service_type' => $job->service_type->label(),
            'status' => $job->status->value,
            'is_simulation' => $job->is_simulation,
            'plan' => $job->plan,
            'histories' => $job->histories,
            'backups' => $job->backups->map(fn ($b) => [
                'path' => $b->parameter_path, 'old' => $b->displayValue('old'), 'new' => $b->displayValue('new'),
                'existed_before' => $b->existed_before, 'restore_status' => $b->restore_status,
            ]),
            'wan' => $job->wanConfigurations,
            'ssids' => $job->ssidConfigurations,
        ]);
    }

    /** POST /api/configuration/{job}/rollback */
    public function rollback(ConfigurationJob $job): JsonResponse
    {
        Gate::authorize('rollback', $job);
        try {
            return response()->json($this->provisioning->rollback($job, request()->user()));
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus);
        }
    }

    /** GET /api/templates : daftar template untuk wizard (tanpa password). */
    public function templates(): JsonResponse
    {
        Gate::authorize('configure-ont');

        return response()->json(ConfigurationTemplate::orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'service_type', 'description', 'payload', 'is_default']));
    }
}

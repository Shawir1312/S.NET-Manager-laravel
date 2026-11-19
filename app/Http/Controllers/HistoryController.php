<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ConfigurationJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HistoryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ConfigurationJob::class);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', 'string', 'max:20'],
            'service_type' => ['nullable', 'in:bridge,pppoe'], 'mode' => ['nullable', 'in:simulation,production'],
        ]);

        $jobs = $this->scoped(ConfigurationJob::query())
            ->with(['device:id,serial_number,vendor,product_class', 'user:id,name', 'wanConfigurations:id,configuration_job_id,vlan_id'])
            ->when($f['q'] ?? null, fn ($q, $t) => $q->whereHas('device', fn ($d) => $d->where('serial_number', 'like', '%'.addcslashes($t, '%_').'%')))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['service_type'] ?? null, fn ($q, $s) => $q->where('service_type', $s))
            ->when(($f['mode'] ?? null) === 'simulation', fn ($q) => $q->where('is_simulation', true))
            ->when(($f['mode'] ?? null) === 'production', fn ($q) => $q->where('is_simulation', false))
            ->latest('id')->paginate(25)->withQueryString();

        return view('history.index', ['jobs' => $jobs, 'filters' => $f]);
    }

    public function show(ConfigurationJob $job): View
    {
        Gate::authorize('view', $job);
        $job->load(['device', 'user:id,name', 'histories', 'backups', 'wanConfigurations.bindings', 'ssidConfigurations', 'template:id,name']);

        return view('history.show', compact('job'));
    }

    /** Teknisi hanya melihat job miliknya; admin melihat semua. */
    private function scoped(Builder $q): Builder
    {
        $user = auth()->user();

        return $user->role === Role::Admin ? $q : $q->where('user_id', $user->id);
    }
}

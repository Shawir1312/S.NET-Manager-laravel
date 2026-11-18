<?php

namespace App\Http\Controllers;

use App\Enums\Vendor;
use App\Models\OntDevice;
use App\Repositories\OntDeviceRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request, OntDeviceRepository $devices): View
    {
        Gate::authorize('view-devices');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'vendor' => ['nullable', 'in:'.implode(',', array_column(Vendor::cases(), 'value')).',other'],
            'status' => ['nullable', 'in:online,offline'],
        ]);

        return view('devices.index', [
            'devices' => $devices->paginate($filters),
            'filters' => $filters,
            'vendors' => Vendor::cases(),
        ]);
    }

    public function show(OntDevice $device): View
    {
        Gate::authorize('view-devices');

        return view('devices.show', ['device' => $device->load('ontModel')]);
    }
}

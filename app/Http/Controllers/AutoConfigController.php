<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\PppoeCustomer;
use App\Services\Pppoe\CustomerOntProvisioner;
use App\Services\Radius\RadiusSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AutoConfigController extends Controller
{
    /** Wizard config ONT. `?customer=ID&service=pppoe|bridge` = config manual untuk pelanggan (form terisi otomatis). */
    public function index(Request $request, CustomerOntProvisioner $ont, RadiusSync $radius): View
    {
        Gate::authorize('view-devices');

        $prefill = null;
        // Menu "Config ONT Manual" / "Setting Hotspot ONT": hanya memilih layanan & template default.
        $service = ServiceType::tryFrom((string) $request->query('service'));
        if ($service && ! $request->filled('customer')) {
            $prefill = ['customer' => null, 'sn' => null, 'genie_server_id' => null, 'service_type' => $service->value, 'template_id' => null];
        }
        if ($request->filled('customer') && $request->user()->can('configure-ont')) {
            $customer = PppoeCustomer::with('router')->findOrFail($request->integer('customer'));
            $service = ServiceType::tryFrom((string) $request->query('service')) ?? ServiceType::Pppoe;
            $template = rescue(fn () => $ont->templateFor($customer, $service), null, report: false);
            $isPppoe = $service === ServiceType::Pppoe;

            $prefill = [
                'customer' => $customer->full_name,
                'sn' => $customer->ont_sn,
                'genie_server_id' => $customer->router?->genie_acs_setting_id,
                'service_type' => $service->value,
                'template_id' => $template?->id,
                'first_name' => Str::before(trim($customer->full_name).' ', ' '),
                'pppoe_username' => $isPppoe ? $customer->pppoe_username : null,
                'pppoe_password' => $isPppoe ? $radius->passwordOf($customer->pppoe_username) : null,
                'vlan_id' => $isPppoe ? $customer->ont_vlan : null,
                'wifi_ssid' => $isPppoe ? $customer->ont_wifi_ssid : null,
                'wifi_pass' => $isPppoe ? $customer->ont_wifi_pass : null,
            ];
        }

        return view('auto-config.index', compact('prefill'));
    }
}

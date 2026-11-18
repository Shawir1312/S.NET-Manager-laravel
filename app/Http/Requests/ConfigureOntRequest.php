<?php

namespace App\Http\Requests;

use App\Services\ONT\Mappers\SSIDMapper;
use App\Services\ONT\Mappers\WANMapper;
use Illuminate\Foundation\Http\FormRequest;

class ConfigureOntRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configure-ont') ?? false;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'integer', 'exists:ont_devices,id'],
            'idempotency_key' => [$this->routeIs('api.ont.configure') ? 'required' : 'nullable', 'uuid'],
            'template_id' => ['nullable', 'integer', 'exists:configuration_templates,id'],
            'service_type' => ['required', 'in:bridge,pppoe'],
            'confirm_overwrite' => ['sometimes', 'boolean'],

            'wan' => ['required', 'array'],
            'wan.mode' => ['required', 'in:new,existing'],
            'wan.existing_path' => ['nullable', 'required_if:wan.mode,existing', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.]+$/'],
            'wan.slot' => ['required', 'in:ip,ppp'],
            'wan.name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\- .]+$/'],
            'wan.vlan_id' => ['required', 'integer', 'between:1,4094'],
            'wan.vlan_priority' => ['nullable', 'integer', 'between:0,7'],
            'wan.service_list' => ['required', 'in:'.implode(',', WANMapper::SERVICE_LISTS)],
            'wan.nat_enabled' => ['nullable', 'boolean'],
            'wan.pppoe_username' => ['nullable', 'required_if:service_type,pppoe', 'string', 'max:64'],
            'wan.pppoe_password' => ['nullable', 'string', 'max:64'],

            'ssids' => ['array', 'max:8'],
            'ssids.*.index' => ['required', 'integer', 'between:1,8', 'distinct'],
            'ssids.*.ssid_name' => ['nullable', 'string', 'max:32'],
            'ssids.*.password' => ['nullable', 'string', 'min:8', 'max:63'],
            'ssids.*.security' => ['nullable', 'in:'.implode(',', SSIDMapper::SECURITY_MODES)],
            'ssids.*.enabled' => ['nullable', 'boolean'],
            'ssids.*.hidden' => ['nullable', 'boolean'],

            'binding' => ['array'],
            'binding.lan' => ['array', 'max:8'],
            'binding.lan.*' => ['integer', 'between:1,8', 'distinct'],
            'binding.wlan' => ['array', 'max:8'],
            'binding.wlan.*' => ['integer', 'between:1,8', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'wan.vlan_id' => 'VLAN ID', 'wan.vlan_priority' => 'VLAN Priority', 'wan.pppoe_username' => 'PPPoE Username',
            'wan.pppoe_password' => 'PPPoE Password', 'wan.name' => 'WAN Name', 'ssids.*.password' => 'password WiFi',
            'ssids.*.ssid_name' => 'nama SSID',
        ];
    }
}

<?php

namespace App\Services\ONT;

use App\Enums\BindingMethod;
use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use App\Models\OntModel;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\Drivers\FiberHome\FiberHomeParameters;
use App\Services\ONT\Drivers\Huawei\HuaweiParameters;
use App\Services\ONT\Drivers\StandardParameters;
use App\Services\ONT\Drivers\ZTE\ZTEDriver;
use App\Services\ONT\Drivers\ZTE\ZTEParameters;

/**
 * ONT dari vendor yang didukung tetapi modelnya belum terdaftar didaftarkan OTOMATIS
 * (status "uji coba") dengan mapping kandidat vendor, lalu diverifikasi oleh MappingVerifier.
 */
class ModelRegistrar
{
    public function ensure(DetectionResult $detection, ParameterTree $tree): ?OntModel
    {
        if ($detection->model || ! $detection->vendor || blank($detection->productClass)) {
            return $detection->model;
        }

        $vendor = $detection->vendor;
        $pc = $detection->productClass;
        $root = $tree->root();

        $model = OntModel::create([
            'vendor' => $vendor,
            'model_name' => $tree->stringValue($root.'.DeviceInfo.ModelName') ?: $pc,
            'product_class' => $pc,
            'firmware_pattern' => '*',
            'status' => ModelSupportStatus::Testing,
            'notes' => 'Didaftarkan otomatis saat ONT '.$tree->deviceId()['serial_number'].' dicari ('.now()->format('Y-m-d H:i').').',
        ]);

        $lan = count($tree->instances($root.'.LANDevice.1.LANEthernetInterfaceConfig'));
        $ssid = count($tree->instances($root.'.LANDevice.1.WLANConfiguration'));
        $model->capability()->create([
            'lan_ports' => $lan ?: null,
            'ssid_count' => $ssid ?: null,
            'binding_method' => $this->bindingMethod($vendor, $pc),
            'supports_bridge' => false,
            'supports_pppoe' => false,
            'vlan_min' => 1,
            'vlan_max' => 4094,
            'extra' => [
                'pppoe_slot' => 'ppp',
                'wan_name_auto' => $vendor === Vendor::FiberHome,
                // ZTE F609 asli menolak AddObject WANConnectionDevice (cwmp.9002): tambah WAN di WCD yang ada.
                'new_wan_strategy' => $vendor === Vendor::ZTE ? 'existing_wcd' : 'new_wcd',
            ],
        ]);

        foreach ($this->candidateMappings($vendor) as $key => [$path, $type, $valueMap]) {
            $model->parameterMappings()->updateOrCreate(['key' => $key], [
                'path_template' => $path, 'value_type' => $type, 'value_map' => $valueMap,
                'verified' => false, 'source' => 'Kandidat mapping '.$vendor->label().' (didaftarkan otomatis)',
            ]);
        }

        return $model->fresh('capability');
    }

    private function bindingMethod(Vendor $vendor, string $pc): BindingMethod
    {
        return match ($vendor) {
            Vendor::FiberHome => BindingMethod::StringList,
            Vendor::Huawei => BindingMethod::Flags,
            Vendor::ZTE => in_array(strtoupper($pc), ZTEDriver::WAN_PLUS_LAN_MODELS, true) ? BindingMethod::WanPlusLan : BindingMethod::Unknown,
        };
    }

    /** @return array<string, array{0: string, 1: string, 2: ?array}> */
    private function candidateMappings(Vendor $vendor): array
    {
        $out = [];
        foreach (StandardParameters::all() as $k => [$p, $t]) {
            $out[$k] = [$p, $t, null];
        }

        [$sets, $serviceValues] = match ($vendor) {
            Vendor::FiberHome => [[FiberHomeParameters::verified(), FiberHomeParameters::unverified()], FiberHomeParameters::serviceListValues()],
            Vendor::ZTE => [[ZTEParameters::unverified()], ZTEParameters::serviceListValues()],
            Vendor::Huawei => [[HuaweiParameters::unverified()], HuaweiParameters::serviceListValues()],
        };
        foreach ($sets as $set) {
            foreach ($set as $k => $def) {
                $out[$k] = [$def[0], $def[1], str_ends_with($k, '.service_list') ? $serviceValues : null];
            }
        }

        return $out;
    }
}

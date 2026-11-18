<?php

namespace Database\Seeders;

use App\Enums\BindingMethod;
use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use App\Models\OntModel;
use App\Services\ONT\Drivers\FiberHome\FiberHomeParameters;
use App\Services\ONT\Drivers\Huawei\HuaweiParameters;
use App\Services\ONT\Drivers\StandardParameters;
use App\Services\ONT\Drivers\ZTE\ZTEParameters;
use Illuminate\Database\Seeder;

/**
 * Model ONT dari ProductClass yang tercatat di log GenieACS (192.168.20.254).
 * Semua berstatus "testing" (hanya simulasi) sampai provisioning diuji di ONT asli.
 * Mapping "verified = true" HANYA untuk path yang sudah dibaca dari ONT asli.
 */
class OntModelSeeder extends Seeder
{
    public function run(): void
    {
        // FiberHome HG6145D2: struktur sudah dibaca dari ONT asli.
        $fh = $this->model(Vendor::FiberHome, 'HG6145D2', BindingMethod::StringList,
            'Struktur parameter dibaca dari ONT asli. Uji produksi 2026-11-18 (FHTT9D34CF80): WAN bridge IP_Bridged VLAN 300 via AddObject '
            .'berhasil + rollback berhasil. Nama WAN dibuat otomatis oleh ONT. Binding & PPPoE belum diuji.',
            ['lan_ports' => 4, 'ssid_count' => 8, 'has_5g' => true,
                'extra' => ['ssid_band' => ['2.4GHz' => [1, 2, 3, 4], '5GHz' => [5, 6, 7, 8]], 'wan_name_auto' => true]]);
        $this->mappings($fh, StandardParameters::all(), false, StandardParameters::SOURCE);
        foreach (FiberHomeParameters::unverified() as $key => [$path, $type, $note]) {
            $this->mapping($fh, $key, $path, $type, false, FiberHomeParameters::VERIFIED_SOURCE.' - '.$note);
        }
        $this->mappings($fh, FiberHomeParameters::verified(), true, FiberHomeParameters::VERIFIED_SOURCE, FiberHomeParameters::serviceListValues());

        $fh2 = $this->model(Vendor::FiberHome, 'HG6243C', BindingMethod::StringList);
        $this->mappings($fh2, StandardParameters::all(), false, StandardParameters::SOURCE);

        foreach (['F670L' => BindingMethod::WanPlusLan, 'F679L' => BindingMethod::Unknown, 'F609' => BindingMethod::StringList] as $pc => $binding) {
            $m = $this->model(Vendor::ZTE, $pc, $binding, null, ['extra' => ['pppoe_slot' => 'ppp', 'new_wan_strategy' => 'existing_wcd']]);
            $this->mappings($m, StandardParameters::all(), false, StandardParameters::SOURCE);
            $this->mappings($m, ZTEParameters::unverified(), false, ZTEParameters::SOURCE, ZTEParameters::serviceListValues());
        }

        foreach (['HS8145C5', 'HG8245H5'] as $pc) {
            $m = $this->model(Vendor::Huawei, $pc, BindingMethod::Flags);
            $this->mappings($m, StandardParameters::all(), false, StandardParameters::SOURCE);
            $this->mappings($m, HuaweiParameters::unverified(), false, HuaweiParameters::SOURCE, HuaweiParameters::serviceListValues());
        }
    }

    private function model(Vendor $vendor, string $productClass, BindingMethod $binding, ?string $notes = null, array $capability = []): OntModel
    {
        $model = OntModel::updateOrCreate(
            ['vendor' => $vendor, 'product_class' => $productClass, 'firmware_pattern' => '*'],
            ['model_name' => $productClass, 'status' => ModelSupportStatus::Testing,
                'notes' => $notes ?? 'Terdeteksi di log GenieACS. Parameter belum dibaca dari ONT asli.'],
        );

        $model->capability()->updateOrCreate([], $capability + [
            'binding_method' => $binding,
            'supports_bridge' => false,
            'supports_pppoe' => false,
            'vlan_min' => 1,
            'vlan_max' => 4094,
        ]);

        return $model;
    }

    private function mappings(OntModel $model, array $map, bool $verified, string $source, array $serviceValues = []): void
    {
        foreach ($map as $key => $def) {
            $valueMap = str_ends_with($key, '.service_list') ? $serviceValues ?: null : null;
            $this->mapping($model, $key, $def[0], $def[1], $verified, $source, $valueMap);
        }
    }

    private function mapping(OntModel $model, string $key, string $path, string $type, bool $verified, string $source, ?array $valueMap = null): void
    {
        $model->parameterMappings()->updateOrCreate(['key' => $key], [
            'path_template' => $path, 'value_type' => $type, 'value_map' => $valueMap,
            'verified' => $verified, 'verification_level' => $verified ? 'path' : null,
            'verified_device' => $verified ? 'FHTT9D34CF80' : null,
            'verified_at' => $verified ? '2026-11-17 14:18:00' : null, 'source' => $source,
        ]);
    }
}

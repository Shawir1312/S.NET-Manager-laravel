<?php

namespace App\Services\ONT;

use App\Enums\ServiceType;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\OntDevice;
use App\Models\User;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Support\Str;

/**
 * Setting ONT hotspot cepat: semua parameter (WAN/VLAN, binding, SSID, keamanan WiFi) diambil dari template hotspot;
 * operator hanya mengisi nama (pengganti {nama} di SSID, atau jadi nama SSID bila template tidak menetapkan nama).
 */
class HotspotOntSetup
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function template(?int $templateId = null): ConfigurationTemplate
    {
        $query = ConfigurationTemplate::where('service_type', ServiceType::Bridge->value);
        $template = ($templateId ? (clone $query)->whereKey($templateId)->first() : null)
            ?? $query->orderByDesc('is_default')->orderBy('id')->first();
        if (! $template) {
            throw new ProvisioningException('Belum ada template Hotspot. Buat dulu di menu Template ONT.', 'NO_TEMPLATE', 422);
        }

        return $template;
    }

    /**
     * Input wizard (format ConfigureOntRequest) dari template hotspot + nama.
     *
     * @return array<string, mixed>
     */
    public function buildInput(ConfigurationTemplate $template, string $name): array
    {
        $p = $template->payload;
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $fixedNames = collect($p['ssids'] ?? [])->pluck('ssid_name', 'index');
        [$security, $password] = $template->hotspotWifi();

        $ssids = [];
        foreach ($p['binding']['wlan'] ?? [] as $index) {
            $fixed = $fixedNames[$index] ?? null;
            $ssids[] = [
                'index' => (int) $index,
                'ssid_name' => Str::limit($fixed !== null ? str_replace('{nama}', $name, $fixed) : $name, 32, ''),
                'password' => $password,
                'security' => $security,
                'enabled' => true, 'hidden' => null,
            ];
        }

        return [
            'service_type' => ServiceType::Bridge->value,
            'wan' => [
                'mode' => 'new', 'existing_path' => null,
                'slot' => $p['wan']['slot'] ?? 'ip',
                'name' => $p['wan']['name'] ?? null,
                'vlan_id' => (int) $p['wan']['vlan_id'],
                'vlan_priority' => $p['wan']['vlan_priority'] ?? null,
                'service_list' => $p['wan']['service_list'] ?? 'INTERNET',
                'nat_enabled' => null, 'pppoe_username' => null, 'pppoe_password' => null,
            ],
            'ssids' => $ssids,
            'binding' => ['lan' => $p['binding']['lan'] ?? [], 'wlan' => $p['binding']['wlan'] ?? []],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** Ringkasan template untuk ditampilkan sebelum diterapkan (tanpa password). @return array<string, mixed> */
    public function summary(ConfigurationTemplate $template, string $name = '{nama}'): array
    {
        $input = $this->buildInput($template, $name);
        [$security] = $template->hotspotWifi();

        return [
            'vlan_id' => $input['wan']['vlan_id'], 'slot' => $input['wan']['slot'], 'service_list' => $input['wan']['service_list'],
            'lan' => $input['binding']['lan'], 'ssids' => array_column($input['ssids'], 'ssid_name', 'index'),
            'security' => match ($security) {
                'None' => 'Tanpa password (terbuka)',
                '11i' => 'Pakai password (WPA2)',
                default => 'Tidak diubah (ikut ONT)',
            },
        ];
    }

    /** Validasi lewat preview lalu kirim konfigurasi. */
    public function apply(OntDevice $device, ConfigurationTemplate $template, string $name, User $user): ConfigurationJob
    {
        $input = $this->buildInput($template, $name);
        $errors = $this->provisioning->preview($device, $input)['errors'] ?? [];
        if ($errors !== []) {
            throw new ProvisioningException(implode(' ', $errors), 'PREVIEW_FAILED', 422);
        }

        return $this->provisioning->start($device, $input, $user, $template->id);
    }
}

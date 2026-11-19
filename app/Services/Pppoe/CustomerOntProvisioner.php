<?php

namespace App\Services\Pppoe;

use App\Enums\ServiceType;
use App\Jobs\StartQueuedProvisioning;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\OntDevice;
use App\Models\PppoeCustomer;
use App\Models\User;
use App\Services\GenieACS\GenieACSService;
use App\Services\ONT\OntLookupService;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Support\Str;

/**
 * Jembatan pelanggan PPPoE S.NET -> mesin provisioning ont-autoconfig.
 * Menggantikan push GenieACS lama di save_pppoe_customer.php.
 */
class CustomerOntProvisioner
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    /** Cari ONT pelanggan di GenieACS milik router-nya (SN persis) dan simpan ke cache ONT. */
    public function findDevice(PppoeCustomer $customer): OntDevice
    {
        if ($setting = $customer->router?->genieAcsSetting) {
            GenieACSService::use($setting);
        }

        $result = app(OntLookupService::class)->search($customer->ont_sn);
        $devices = $result['devices'];
        if ($devices === []) {
            throw new ProvisioningException($result['message'], 'DEVICE_NOT_FOUND', 404);
        }
        if (count($devices) > 1) {
            throw new ProvisioningException('Ada beberapa ONT dengan SN ini. Pilih manual di tab Config ONT.', 'MULTIPLE_DEVICES', 409);
        }

        return OntDevice::findOrFail($devices[0]['id']);
    }

    /** Template yang dipakai: pilihan form -> default router -> default global per jenis. */
    public function templateFor(PppoeCustomer $customer, ServiceType $type, ?int $templateId = null): ConfigurationTemplate
    {
        $routerTemplateId = $type === ServiceType::Pppoe ? $customer->router?->pppoe_template_id : $customer->router?->hotspot_template_id;

        $template = ConfigurationTemplate::where('service_type', $type->value)
            ->whereKey($templateId ?? $routerTemplateId)->first()
            ?? ConfigurationTemplate::where('service_type', $type->value)->orderByDesc('is_default')->orderBy('id')->first();

        if (! $template) {
            throw new ProvisioningException("Belum ada template {$type->label()}. Buat dulu di menu Template.", 'NO_TEMPLATE', 422);
        }

        return $template;
    }

    /**
     * Bangun input wizard (format ConfigureOntRequest) dari template + data pelanggan.
     *
     * @return array<string, mixed>
     */
    public function buildInput(PppoeCustomer $customer, ConfigurationTemplate $template, ?string $pppoePassword): array
    {
        $p = $template->payload;
        $isPppoe = $template->service_type === ServiceType::Pppoe;
        $firstName = Str::before(trim($customer->full_name), ' ') ?: $customer->full_name;
        $fixedNames = collect($p['ssids'] ?? [])->pluck('ssid_name', 'index');
        [$hotspotSecurity, $hotspotPassword] = $isPppoe ? [null, null] : $template->hotspotWifi();

        $ssids = [];
        foreach ($p['binding']['wlan'] ?? [] as $index) {
            $fixed = $fixedNames[$index] ?? null;
            // SSID dengan nama tetap (hotspot) tidak diberi password pelanggan; hotspot memakai keamanan dari template.
            $password = $fixed === null && $isPppoe ? ($customer->ont_wifi_pass ?: null) : null;
            $ssids[] = [
                'index' => (int) $index,
                'ssid_name' => $fixed !== null ? str_replace('{nama}', $firstName, $fixed) : ($customer->ont_wifi_ssid ?: null),
                'password' => $isPppoe ? $password : $hotspotPassword,
                'security' => $isPppoe ? ($password ? '11i' : null) : $hotspotSecurity, // WPA2-PSK; mesin wajib tahu mode saat password diubah
                'enabled' => true, 'hidden' => null,
            ];
        }

        return [
            'service_type' => $template->service_type->value,
            'wan' => [
                'mode' => 'new',
                'existing_path' => null,
                'slot' => $isPppoe ? 'ppp' : ($p['wan']['slot'] ?? 'ip'),
                'name' => $p['wan']['name'] ?? null,
                // VLAN PPPoE bisa beda per pelanggan; hotspot selalu dari template.
                'vlan_id' => $isPppoe && $customer->ont_vlan ? (int) $customer->ont_vlan : (int) $p['wan']['vlan_id'],
                'vlan_priority' => $p['wan']['vlan_priority'] ?? null,
                'service_list' => $p['wan']['service_list'] ?? 'INTERNET',
                'nat_enabled' => $isPppoe ? ($p['wan']['nat_enabled'] ?? true) : null,
                'pppoe_username' => $isPppoe ? $customer->pppoe_username : null,
                'pppoe_password' => $isPppoe ? $pppoePassword : null,
            ],
            'ssids' => $ssids,
            'binding' => ['lan' => $p['binding']['lan'] ?? [], 'wlan' => $p['binding']['wlan'] ?? []],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * Push PPPoE (dan opsional Hotspot) ke ONT pelanggan. Job hotspot diantrekan
     * setelah job PPPoE karena mesin hanya mengizinkan satu job aktif per ONT.
     *
     * @return array{job: ConfigurationJob, warnings: list<string>}
     */
    public function push(PppoeCustomer $customer, User $user, ?string $pppoePassword, bool $withHotspot = false, ?int $pppoeTemplateId = null, ?int $hotspotTemplateId = null): array
    {
        $device = $this->findDevice($customer);
        $pppoeTemplate = $this->templateFor($customer, ServiceType::Pppoe, $pppoeTemplateId);
        $hotspotTemplate = $withHotspot ? $this->templateFor($customer, ServiceType::Bridge, $hotspotTemplateId) : null;

        // Port/SSID template yang tidak ada di ONT ini dilewati; yang ada tetap diterapkan.
        [$pppoeInput, $skipped] = $this->provisioning->fitInterfaces($device, $this->buildInput($customer, $pppoeTemplate, $pppoePassword));
        $job = $this->provisioning->start($device, $pppoeInput, $user, $pppoeTemplate->id);
        $warnings = $skipped === [] ? [] : ['PPPoE — dilewati karena tidak ada di ONT ini: '.implode(', ', $skipped).'.'];

        if ($hotspotTemplate) {
            [$input, $skipped] = $this->provisioning->fitInterfaces($device, $this->buildInput($customer, $hotspotTemplate, null));
            if ($skipped !== []) {
                $warnings[] = 'Hotspot — dilewati karena tidak ada di ONT ini: '.implode(', ', $skipped).'.';
            }
            // Cek dulu sekarang: kalau ditolak (mis. VLAN bentrok) admin langsung tahu, bukan gagal diam-diam di antrean.
            $errors = $this->provisioning->preview($device, $input)['errors'] ?? [];
            if ($errors !== []) {
                $warnings[] = 'Hotspot tidak diantrekan: '.implode(' ', $errors);
            } else {
                StartQueuedProvisioning::dispatch($device->id, $input, $user->id, $hotspotTemplate->id)->delay(now()->addSeconds(5));
            }
        }

        return ['job' => $job, 'warnings' => $warnings];
    }
}

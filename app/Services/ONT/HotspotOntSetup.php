<?php

namespace App\Services\ONT;

use App\Enums\ServiceType;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\OntDevice;
use App\Models\User;
use App\Services\GenieACS\Exceptions\GenieACSException;
use App\Services\GenieACS\GenieACSService;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Support\Str;

/**
 * Setting ONT hotspot cepat: semua parameter (WAN/VLAN, binding, SSID, keamanan WiFi) diambil dari template hotspot.
 * Nama yang diisi operator disimpan sebagai tag/komentar ONT di GenieACS (penanda ONT milik siapa).
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
    public function buildInput(ConfigurationTemplate $template, string $name = ''): array
    {
        $p = $template->payload;
        $name = self::cleanName($name);
        $fixedNames = collect($p['ssids'] ?? [])->pluck('ssid_name', 'index');
        [$security, $password] = $template->hotspotWifi();

        $ssids = [];
        foreach ($p['binding']['wlan'] ?? [] as $index) {
            $fixed = $fixedNames[$index] ?? null;
            $ssids[] = [
                'index' => (int) $index,
                // Nama SSID murni dari template ({nama} opsional); SSID tanpa nama di template tidak diganti namanya.
                'ssid_name' => $fixed !== null ? Str::limit(str_replace('{nama}', $name, $fixed), 32, '') : null,
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
            'lan' => $input['binding']['lan'],
            'ssids' => array_map(fn ($x) => $x ?? '(nama SSID tidak diubah)', array_column($input['ssids'], 'ssid_name', 'index')),
            'security' => match ($security) {
                'None' => 'Tanpa password (terbuka)',
                '11i' => 'Pakai password (WPA2)',
                default => 'Tidak diubah (ikut ONT)',
            },
        ];
    }

    /** Rapikan nama: spasi tunggal, maks. 32 karakter. */
    public static function cleanName(string $name): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $name)), 32, '');
    }

    /**
     * Pasang nama sebagai tag ONT di GenieACS; tag lama yang dulu dipasang dari menu ini diganti.
     * Tag lain (mis. untuk preset GenieACS) tidak disentuh.
     */
    public function tagDevice(OntDevice $device, string $name): void
    {
        $tag = self::cleanName($name);
        $acs = GenieACSService::forDevice($device);
        $old = $device->parameters_snapshot['acs_tag'] ?? null;
        if ($old && $old !== $tag) {
            rescue(fn () => $acs->removeTag($device->genieacs_id, $old), report: false);
        }
        $acs->addTag($device->genieacs_id, $tag);
        $snapshot = (array) $device->parameters_snapshot;
        $snapshot['tags'] = array_values(array_unique([$tag, ...array_diff($snapshot['tags'] ?? [], array_filter([$old]))]));
        $snapshot['acs_tag'] = $tag;
        $device->forceFill(['parameters_snapshot' => $snapshot])->save();
    }

    /**
     * Pasang tag nama, validasi lewat preview, lalu kirim konfigurasi.
     *
     * @return array{job: ConfigurationJob, warnings: list<string>}
     */
    public function apply(OntDevice $device, ConfigurationTemplate $template, string $name, User $user): array
    {
        // Port/SSID template yang tidak ada di ONT ini dilewati; yang ada tetap diterapkan.
        [$input, $skipped] = $this->provisioning->fitInterfaces($device, $this->buildInput($template, $name));
        $errors = $this->provisioning->preview($device, $input)['errors'] ?? [];
        if ($errors !== []) {
            throw new ProvisioningException(implode(' ', $errors), 'PREVIEW_FAILED', 422);
        }

        $warnings = $skipped === [] ? [] : ['Dilewati karena tidak ada di ONT ini: '.implode(', ', $skipped).'.'];
        try {
            $this->tagDevice($device, $name);
        } catch (GenieACSException $e) {
            $warnings[] = 'Nama belum tersimpan sebagai tag di GenieACS: '.$e->getMessage();
        }

        return ['job' => $this->provisioning->start($device, $input, $user, $template->id), 'warnings' => $warnings];
    }
}

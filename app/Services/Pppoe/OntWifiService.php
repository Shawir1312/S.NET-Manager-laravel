<?php

namespace App\Services\Pppoe;

use App\Enums\ServiceType;
use App\Models\PppoeCustomer;
use App\Services\Audit\AuditLogger;
use App\Services\GenieACS\GenieACSService;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\ONTDetector;
use App\Services\ONT\ONTManager;
use App\Services\Provisioning\ConfigurationRequest;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningPlan;
use App\Services\Provisioning\ProvisioningService;

/**
 * Ganti nama & password WiFi dari portal pelanggan memakai driver ONT ont-autoconfig
 * (path parameter per vendor/model yang sudah terverifikasi), tanpa menyentuh WAN.
 */
class OntWifiService
{
    public function __construct(
        private readonly CustomerOntProvisioner $ont,
        private readonly ONTDetector $detector,
        private readonly ONTManager $manager,
        private readonly AuditLogger $audit,
    ) {}

    /** @return string pesan untuk pelanggan */
    public function change(PppoeCustomer $customer, ?string $ssid, ?string $password): string
    {
        $device = $customer->ontDevice() ?? $this->ont->findDevice($customer);
        $acs = GenieACSService::forDevice($device);
        $tree = $acs->getDeviceDetails($device->genieacs_id);
        $ctx = DeviceContext::build($device, $tree, $this->detector->detect($tree));

        // SSID milik pelanggan = WLAN di template PPPoE yang tidak bernama tetap (bukan SSID hotspot).
        $template = $this->ont->templateFor($customer, ServiceType::Pppoe);
        $fixed = collect($template->payload['ssids'] ?? [])->pluck('index')->all();
        $indexes = array_values(array_diff($template->payload['binding']['wlan'] ?? [1], $fixed)) ?: [1];

        $req = ConfigurationRequest::fromArray([
            'service_type' => 'pppoe', 'wan' => ['vlan_id' => 1, 'slot' => 'ppp'],
            'ssids' => array_map(fn ($i) => ['index' => $i, 'ssid_name' => $ssid, 'password' => $password, 'security' => $password ? '11i' : null], $indexes),
        ]);
        $plan = new ProvisioningPlan;
        $this->manager->driverFor($ctx->detection)->configureSSID($ctx, $req, $plan);
        if ($plan->hasErrors()) {
            throw new ProvisioningException(implode(' ', $plan->errors));
        }

        $customer->forceFill(array_filter(['ont_wifi_ssid' => $ssid, 'ont_wifi_pass' => $password]))->save();
        $this->audit->log('portal.wifi_changed', $customer, ['ssid' => $ssid, 'password_changed' => $password !== null, 'indexes' => $indexes]);

        if (! ProvisioningService::isProduction()) {
            return 'Mode simulasi: pengaturan WiFi disimpan tapi belum dikirim ke modem.';
        }
        $queued = false;
        foreach ($plan->toArray(redact: false)['steps'] as $step) {
            $r = $acs->setParameterValues($device->genieacs_id, array_map(fn ($v) => [$v['path'], $v['value'], $v['type']], $step['values']));
            if ($r['state'] === 'fault') {
                throw new ProvisioningException('Modem menolak perubahan WiFi.');
            }
            $queued = $queued || $r['state'] === 'timeout';
        }

        return $queued
            ? 'Perubahan WiFi dikirim dan akan diterapkan saat modem terhubung ke server (beberapa menit).'
            : 'WiFi berhasil diperbarui. Perangkat Anda akan terputus sebentar, sambungkan ulang dengan nama/password baru.';
    }
}

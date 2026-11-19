<?php

namespace App\Services\ONT\Drivers;

use App\Enums\BindingMethod;
use App\Enums\ServiceType;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\DeviceInfoBuilder;
use App\Services\ONT\Exceptions\ParameterUnavailableException;
use App\Services\ONT\Mappers\BindingMapper;
use App\Services\ONT\Mappers\SSIDMapper;
use App\Services\ONT\Mappers\WANMapper;
use App\Services\ONT\ONTInterface;
use App\Services\ONT\Validators\ONTValidator;
use App\Services\ONT\Validators\WANValidator;
use App\Services\Provisioning\ConfigurationRequest;
use App\Services\Provisioning\ProvisioningPlan;
use App\Services\Provisioning\WanTarget;
use App\Support\Tr069Value;

/**
 * Logika bersama berbasis mapping parameter. Path SELALU diambil dari tabel
 * ont_parameter_mappings (per model), tidak pernah di-hardcode di sini.
 */
abstract class AbstractDriver implements ONTInterface
{
    public function __construct(protected readonly DeviceInfoBuilder $infoBuilder) {}

    public function getDeviceInfo(DeviceContext $ctx): array
    {
        return $this->infoBuilder->build($ctx->tree, $ctx->detection);
    }

    public function getCapabilities(DeviceContext $ctx): array
    {
        $info = $this->getDeviceInfo($ctx);

        return $info['capabilities'] + ['counts' => $info['counts'], 'pppoe_slot' => $this->pppoeSlot($ctx)];
    }

    public function getWANConnections(DeviceContext $ctx): array
    {
        $out = [];
        foreach ($this->getDeviceInfo($ctx)['wan_connections'] as $wan) {
            $vars = ['wcd' => $wan['wcd'], 'i' => $wan['index']];
            $slot = $wan['slot'];
            $vlan = $ctx->read("wan.{$slot}.vlan_id", $vars);
            if (($vlan === null || (int) $vlan === 0) && $ctx->hasMapping('wan.link.vlan_id')) {
                $link = $ctx->read('wan.link.vlan_id', $vars);
                $vlan = ($link !== null && (int) $link > 0) ? $link : $vlan;
            }
            $out[] = $wan + [
                'short_path' => str_replace($ctx->root().'.WANDevice.1.', '', $wan['path']),
                'vlan_id' => $vlan === null || (int) $vlan === 0 ? null : (int) $vlan,
                'service_list' => $ctx->read("wan.{$slot}.service_list", $vars),
            ];
        }

        return $out;
    }

    public function buildPlan(DeviceContext $ctx, ConfigurationRequest $req): ProvisioningPlan
    {
        $plan = new ProvisioningPlan;
        if (! ONTValidator::supported($ctx, $plan)) {
            return $plan;
        }

        $wans = $this->getWANConnections($ctx);
        $slot = $req->wan['slot'];
        if ($req->serviceType === ServiceType::Pppoe && $slot !== $this->pppoeSlot($ctx)) {
            $plan->error('PPPoE pada model ini harus memakai slot WAN '.strtoupper($this->pppoeSlot($ctx)).' (parameter Username/Password PPPoE hanya tersedia di slot tersebut).');
        }
        WANValidator::validate($ctx, $req, $wans, $plan);

        $wan = $req->wan['mode'] === 'existing' && $req->wan['existing_path']
            ? (WanTarget::fromPath($req->wan['existing_path'], $ctx->root()) ?? $this->newWanTarget($ctx, $slot, $plan))
            : $this->newWanTarget($ctx, $slot, $plan);

        // Bila ada SSID yang harus dibuat: buat & aktifkan SSID DULU, baru WAN dibuat lalu di-bind.
        // FiberHome menolak binding (cwmp.9002) ke SSID yang dibuat setelah WAN / masih nonaktif.
        // Tanpa SSID baru, urutan lama dipertahankan (WiFi tidak berubah bila pembuatan WAN gagal).
        $this->planMissingSsids($ctx, $req, $plan);
        $ssidFirst = collect($plan->steps)->contains('type', 'ensure_instance');
        if ($ssidFirst) {
            $this->attempt($plan, fn () => $this->configureSSID($ctx, $req, $plan));
        }

        if ($wan->isNew) {
            $this->addNewWanSteps($ctx, $wan, $plan);
        }

        $this->attempt($plan, fn () => $req->serviceType === ServiceType::Bridge
            ? $this->createWANBridge($ctx, $req, $wan, $plan)
            : $this->createWANPPPoE($ctx, $req, $wan, $plan));
        $this->attempt($plan, fn () => $this->configureVLAN($ctx, $req, $wan, $plan));
        $this->attempt($plan, fn () => $this->configureBinding($ctx, $req, $wan, $plan));
        if (! $ssidFirst) {
            $this->attempt($plan, fn () => $this->configureSSID($ctx, $req, $plan));
        }

        $plan->summary = $this->summary($ctx, $req, $wan);

        return $plan;
    }

    public function createWANBridge(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void
    {
        $w = $req->wan;
        $s = $wan->slot;
        $vars = $wan->vars();

        $plan->setValues(array_filter([
            $this->nameValue($ctx, $req, $plan, $s, $vars, $w['service_list']),
            $this->val($ctx, $plan, "wan.{$s}.connection_type", $vars, WANMapper::connectionType(ServiceType::Bridge, $s)),
            $this->val($ctx, $plan, "wan.{$s}.service_list", $vars, $ctx->mapValue("wan.{$s}.service_list", $w['service_list'])),
            $this->val($ctx, $plan, "wan.{$s}.enable", $vars, true),
        ]), 'Konfigurasi WAN Bridge ('.WANMapper::connectionType(ServiceType::Bridge, $s).')');
    }

    public function createWANPPPoE(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void
    {
        $w = $req->wan;
        $s = $wan->slot;
        $vars = $wan->vars();

        $values = array_filter([
            $this->nameValue($ctx, $req, $plan, $s, $vars, 'INTERNET'),
            $this->val($ctx, $plan, "wan.{$s}.connection_type", $vars, WANMapper::connectionType(ServiceType::Pppoe, $s)),
            $this->val($ctx, $plan, "wan.{$s}.service_list", $vars, $ctx->mapValue("wan.{$s}.service_list", 'INTERNET')),
            $this->val($ctx, $plan, "wan.{$s}.nat_enabled", $vars, $w['nat_enabled'] ?? true),
            $this->val($ctx, $plan, 'wan.ppp.username', $vars, (string) $w['pppoe_username']),
        ]);
        if ($w['pppoe_password'] !== null) {
            $values[] = $this->val($ctx, $plan, 'wan.ppp.password', $vars, $w['pppoe_password'], true);
        }
        $values[] = $this->val($ctx, $plan, "wan.{$s}.enable", $vars, true);

        $plan->setValues($values, 'Konfigurasi WAN PPPoE (IP_Routed)');
    }

    public function configureVLAN(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void
    {
        $s = $wan->slot;
        $vars = $wan->vars();
        $values = [];

        if ($ctx->hasMapping("wan.{$s}.vlan_enable")) {
            $values[] = $this->val($ctx, $plan, "wan.{$s}.vlan_enable", $vars, true);
        }
        if ($ctx->hasMapping("wan.{$s}.vlan_id")) {
            $values[] = $this->val($ctx, $plan, "wan.{$s}.vlan_id", $vars, $req->wan['vlan_id']);
        } elseif ($ctx->hasMapping('wan.link.vlan_id')) {
            $values[] = $this->val($ctx, $plan, 'wan.link.vlan_id', $vars, $req->wan['vlan_id']);
        } else {
            throw new ParameterUnavailableException("wan.{$s}.vlan_id", $ctx->modelLabel());
        }

        if ($req->wan['vlan_priority'] !== null) {
            $key = $ctx->hasMapping("wan.{$s}.vlan_priority") ? "wan.{$s}.vlan_priority" : ($ctx->hasMapping('wan.link.vlan_priority') ? 'wan.link.vlan_priority' : null);
            if ($key) {
                $values[] = $this->val($ctx, $plan, $key, $vars, $req->wan['vlan_priority']);
            } else {
                $plan->warn('VLAN Priority belum dipetakan untuk model ini, nilai priority diabaikan.');
            }
        }

        $plan->setValues($values, "Konfigurasi VLAN {$req->wan['vlan_id']}");
    }

    /**
     * Banyak ONT (mis. FiberHome) hanya punya SSID 1 (dan 5) secara default; SSID 2-8 harus ditambah dulu.
     * SSID yang diminta tapi belum ada direncanakan untuk dibuat (AddObject) sebelum diatur & di-bind.
     */
    protected function planMissingSsids(DeviceContext $ctx, ConfigurationRequest $req, ProvisioningPlan $plan): void
    {
        $object = $ctx->root().'.LANDevice.1.WLANConfiguration';
        $existing = $ctx->tree->instances($object);
        $max = ONTValidator::maxWlanIndex($ctx); // single-band: SSID 1-4 saja, dual-band: 1-8
        if ($max === 0) {
            return; // ONT tanpa WLAN (atau data WLAN belum terbaca) — biarkan validasi biasa yang melapor.
        }
        $wanted = array_unique([...$req->binding['wlan'], ...array_column($req->ssids, 'index')]);
        sort($wanted);
        foreach ($wanted as $n) {
            if ($n >= 1 && $n <= $max && ! in_array($n, $existing, true)) {
                $plan->ensureInstance($object, $n, "Menambah SSID {$n} di ONT (AddObject, default belum ada)");
                $ctx->planInstance($object, $n);
            }
        }
    }

    public function configureBinding(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan, ProvisioningPlan $plan): void
    {
        $lan = $req->binding['lan'];
        $wlan = $req->binding['wlan'];
        if ($lan === [] && $wlan === []) {
            $plan->warn('Tidak ada LAN/SSID yang di-bind ke WAN ini.');

            return;
        }

        foreach (['lan' => $lan, 'wlan' => $wlan] as $type => $list) {
            foreach ($list as $n) {
                if (! ONTValidator::interfaceExists($ctx, $type, $n)) {
                    $plan->error(($type === 'lan' ? 'LAN ' : 'SSID ').$n.' tidak tersedia di perangkat ini.');
                }
            }
        }

        $this->enableBoundInterfaces($ctx, $req, $plan);

        $s = $wan->slot;
        $vars = $wan->vars();
        $method = $ctx->capability()?->binding_method ?? BindingMethod::Unknown;

        if ($method === BindingMethod::Flags) {
            if (max([0, ...$lan]) > 4 || max([0, ...$wlan]) > 4) {
                $plan->error('Binding model ini hanya mendukung LAN 1-4 dan SSID 1-4.');

                return;
            }
            // Satu flag per perintah: Huawei menolak SELURUH perintah (cwmp.9002) bila satu port tak bisa di-bind
            // (mis. sudah terikat ke WAN lain). Per port → port lain tetap ter-bind & port yang ditolak jelas.
            // WAN baru: flag bawaan semua false, jadi hanya port terpilih yang dikirim (true).
            $any = false;
            foreach (['lan' => ['LAN', $lan], 'ssid' => ['SSID', $wlan]] as $kind => [$label, $selected]) {
                foreach ([1, 2, 3, 4] as $n) {
                    $type = $kind === 'lan' ? 'lan' : 'wlan';
                    if (! $ctx->hasMapping("wan.{$s}.bind_{$kind}{$n}") || ! ONTValidator::interfaceExists($ctx, $type, $n)) {
                        continue;
                    }
                    $any = true;
                    $on = in_array($n, $selected, true);
                    if ($wan->isNew && ! $on) {
                        continue;
                    }
                    $plan->setValues([$this->val($ctx, $plan, "wan.{$s}.bind_{$kind}{$n}", $vars, $on)],
                        ($on ? 'Binding ' : 'Lepas binding ')."{$label} {$n} (flag per port)", optional: true);
                }
            }
            if (! $any) {
                throw new ParameterUnavailableException("wan.{$s}.bind_lan1", $ctx->modelLabel());
            }

            return;
        }

        $paths = BindingMapper::interfacePaths($ctx->root(), $lan, $wlan);
        if ($this->bindingIncludesWan($ctx)) {
            array_unshift($paths, $wan->path());
        }
        $plan->setValues([
            $this->val($ctx, $plan, "wan.{$s}.binding", $vars, BindingMapper::stringList($paths)),
        ], $this->bindingIncludesWan($ctx) ? 'Binding WAN + LAN/WLAN' : 'Binding LAN/WLAN');
    }

    /**
     * Port LAN (dan SSID) yang akan di-bind harus aktif dulu: Huawei/FiberHome menolak binding ke interface
     * nonaktif (cwmp.9002). Hanya interface yang dipilih & sedang nonaktif yang diubah; nilai lama dicadangkan.
     */
    protected function enableBoundInterfaces(DeviceContext $ctx, ConfigurationRequest $req, ProvisioningPlan $plan): void
    {
        $values = [];
        $configuredSsids = array_column(array_filter($req->ssids, fn ($x) => $x['enabled'] === true), 'index');
        foreach (['lan' => 'LANEthernetInterfaceConfig', 'wlan' => 'WLANConfiguration'] as $type => $object) {
            foreach ($req->binding[$type] as $n) {
                if ($type === 'wlan' && in_array($n, $configuredSsids, true)) {
                    continue; // sudah diaktifkan oleh pengaturan SSID
                }
                $path = $ctx->root().".LANDevice.1.{$object}.{$n}.Enable";
                $current = $ctx->tree->value($path);
                if ($ctx->tree->has($path) && ! in_array(strtolower((string) $current), ['1', 'true'], true)) {
                    $values[] = $plan->value($path, true, 'xsd:boolean', "{$type}.enable", true);
                }
            }
        }
        $plan->setValues($values, 'Mengaktifkan port LAN/SSID yang akan di-bind');
    }

    public function configureSSID(DeviceContext $ctx, ConfigurationRequest $req, ProvisioningPlan $plan): void
    {
        foreach ($req->ssids as $ssid) {
            $n = $ssid['index'];
            if (! ONTValidator::interfaceExists($ctx, 'wlan', $n)) {
                $plan->error("SSID {$n} tidak tersedia di perangkat ini.");

                continue;
            }
            foreach (SSIDMapper::validate($ssid) as $e) {
                $plan->error($e);
            }

            $vars = ['ssid' => $n];
            $values = [];

            if ($ssid['ssid_name'] !== null) {
                $values[] = $this->val($ctx, $plan, 'ssid.name', $vars, $ssid['ssid_name']);
            }
            if ($ssid['hidden'] !== null) {
                $values[] = $this->val($ctx, $plan, 'ssid.advertise', $vars, ! $ssid['hidden']);
            }

            if ($ssid['security'] === 'None') {
                $values[] = $this->val($ctx, $plan, 'ssid.beacon_type', $vars, 'None');
            } elseif (in_array($ssid['security'], ['11i', 'WPAand11i'], true)) {
                $currentBeacon = (string) $ctx->read('ssid.beacon_type', $vars);
                if ($ssid['password'] === null && in_array($currentBeacon, ['None', 'Basic', ''], true)) {
                    $plan->error("SSID {$n}: saat ini tanpa password, isi password untuk mengaktifkan WPA.");
                }
                $values[] = $this->val($ctx, $plan, 'ssid.beacon_type', $vars, $ssid['security']);
                foreach (['ssid.auth_mode' => 'PSKAuthentication', 'ssid.encryption' => 'AESEncryption'] as $key => $v) {
                    if ($ctx->hasMapping($key)) {
                        $values[] = $this->val($ctx, $plan, $key, $vars, $v);
                    }
                }
                if ($ssid['security'] === 'WPAand11i') {
                    foreach (['ssid.wpa_auth_mode' => 'PSKAuthentication', 'ssid.wpa_encryption' => 'AESEncryption'] as $key => $v) {
                        if ($ctx->hasMapping($key)) {
                            $values[] = $this->val($ctx, $plan, $key, $vars, $v);
                        }
                    }
                }
                if ($ssid['password'] !== null) {
                    $values[] = $this->val($ctx, $plan, 'ssid.passphrase', $vars, $ssid['password'], true);
                }
            }

            if ($ssid['enabled'] !== null) {
                $currentlyOn = in_array(strtolower((string) $ctx->read('ssid.enable', $vars)), ['1', 'true'], true);
                $clients = (int) $ctx->tree->value($ctx->root().".LANDevice.1.WLANConfiguration.{$n}.TotalAssociations");
                if (! $ssid['enabled'] && $currentlyOn && ! $req->confirmOverwrite) {
                    $plan->error("SSID {$n} sedang aktif".($clients ? " dengan {$clients} klien" : '').'. Centang konfirmasi untuk menonaktifkannya.');
                }
                $values[] = $this->val($ctx, $plan, 'ssid.enable', $vars, $ssid['enabled']);
            }

            $plan->setValues($values, "Konfigurasi SSID {$n}");
        }
    }

    public function verifyConfiguration(DeviceContext $ctx, array $applied): array
    {
        $results = [];
        foreach ($applied as $v) {
            $actual = $ctx->tree->value($v['path']);
            $status = match (true) {
                $v['secret'] => 'secret',
                $actual === null => 'missing',
                self::same($v['value'], $actual) => 'match',
                default => 'mismatch',
            };
            $results[] = [
                'path' => $v['path'], 'key' => $v['key'],
                'expected' => $v['secret'] ? null : $v['value'],
                'actual' => $v['secret'] ? null : $actual,
                'status' => $status,
            ];
        }

        return $results;
    }

    /**
     * Nama WAN. Sebagian firmware membuat nama sendiri dan MENGABAIKAN nilai yang dikirim
     * (terbukti di FiberHome HG6145D2: "2_INTERNET_B_VID_300"). Untuk model seperti itu
     * (capability.extra.wan_name_auto = true) nama tidak dikirim agar verifikasi tidak gagal.
     */
    protected function nameValue(DeviceContext $ctx, ConfigurationRequest $req, ProvisioningPlan $plan, string $slot, array $vars, string $serviceList): ?array
    {
        if ($ctx->capability()?->extra['wan_name_auto'] ?? false) {
            if ($req->wan['name'] !== null) {
                $plan->warn('Model ini membuat nama WAN otomatis (format <no>_<SERVICE>_<B|R>_VID_<vlan>). Nama yang diisi diabaikan.');
            }

            return null;
        }

        return $this->val($ctx, $plan, "wan.{$slot}.name", $vars, $req->wan['name'] ?? WANMapper::defaultName($req->serviceType, $req->wan['vlan_id'], $serviceList));
    }

    /** Slot WAN untuk PPPoE. Default WANPPPConnection; bisa diubah lewat capability.extra.pppoe_slot. */
    protected function pppoeSlot(DeviceContext $ctx): string
    {
        return $ctx->capability()?->extra['pppoe_slot'] ?? 'ppp';
    }

    /** Aturan khusus ZTE F670L: path WAN ikut dalam nilai binding. */
    protected function bindingIncludesWan(DeviceContext $ctx): bool
    {
        return false;
    }

    /**
     * Strategi pembuatan WAN baru (capability.extra.new_wan_strategy):
     *  - new_wcd      : AddObject WANConnectionDevice baru, lalu WANIP/PPPConnection di dalamnya (FiberHome, terbukti).
     *  - existing_wcd : AddObject WANIP/PPPConnection di dalam WANConnectionDevice yang sudah ada
     *                   (ZTE F609 menolak AddObject WANConnectionDevice dengan cwmp.9002).
     */
    protected function newWanStrategy(DeviceContext $ctx): string
    {
        return $ctx->capability()?->extra['new_wan_strategy'] ?? 'new_wcd';
    }

    protected function newWanTarget(DeviceContext $ctx, string $slot, ProvisioningPlan $plan): WanTarget
    {
        if ($this->newWanStrategy($ctx) === 'existing_wcd') {
            $wcds = $ctx->tree->instances($ctx->root().'.WANDevice.1.WANConnectionDevice');
            if ($wcds === []) {
                $plan->error('Tidak ada WANConnectionDevice di ONT untuk menambahkan WAN baru. Refresh parameter ONT terlebih dahulu.');

                return WanTarget::new($slot, $ctx->root());
            }

            return WanTarget::new($slot, $ctx->root(), $wcds[0]);
        }

        return WanTarget::new($slot, $ctx->root());
    }

    /** Langkah AddObject untuk WAN baru sesuai strategi model. */
    protected function addNewWanSteps(DeviceContext $ctx, WanTarget $wan, ProvisioningPlan $plan): void
    {
        $base = $ctx->root().'.WANDevice.1.WANConnectionDevice';
        $object = WANMapper::objectName($wan->slot);

        if ($wan->wcd === ProvisioningPlan::MARK_WCD) {
            $plan->addObject($base, 'WCD', 'Membuat WANConnectionDevice baru (AddObject)');
        }
        $plan->addObject("{$base}.{$wan->wcd}.{$object}", 'I', "Membuat {$object} baru di WANConnectionDevice.".($wan->wcd === ProvisioningPlan::MARK_WCD ? '(baru)' : $wan->wcd).' (AddObject)');
    }

    /** @return array{path: string, value: mixed, type: string, key: string, secret: bool} */
    protected function val(DeviceContext $ctx, ProvisioningPlan $plan, string $key, array $vars, mixed $value, bool $secret = false): array
    {
        $type = $ctx->type($key);

        return $plan->value($ctx->path($key, $vars), Tr069Value::cast($value, $type), $type, $key, $ctx->verified($key), $secret);
    }

    protected function attempt(ProvisioningPlan $plan, callable $fn): void
    {
        try {
            $fn();
        } catch (ParameterUnavailableException $e) {
            $plan->error($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    protected function summary(DeviceContext $ctx, ConfigurationRequest $req, WanTarget $wan): array
    {
        $w = $req->wan;

        return [
            'ont' => [
                'vendor' => $ctx->detection->vendor?->label(),
                'model' => $ctx->model()?->model_name ?? $ctx->detection->productClass,
                'serial_number' => $ctx->device->serial_number,
                'firmware' => $ctx->detection->softwareVersion,
            ],
            'wan' => [
                'type' => $req->serviceType->label(),
                'mode' => WANMapper::connectionType($req->serviceType, $wan->slot),
                'slot' => strtoupper($wan->slot),
                'target' => $wan->isNew ? 'WAN baru (AddObject)' : str_replace($ctx->root().'.WANDevice.1.', '', $wan->path()),
                'name' => ($ctx->capability()?->extra['wan_name_auto'] ?? false)
                    ? 'otomatis oleh ONT (mis. '.($wan->isNew ? 'N' : $wan->wcd).'_'.strtoupper($req->serviceType->value === 'pppoe' ? 'INTERNET' : $w['service_list']).'_'.($req->serviceType->value === 'pppoe' ? 'R' : 'B').'_VID_'.$w['vlan_id'].')'
                    : ($w['name'] ?? WANMapper::defaultName($req->serviceType, $w['vlan_id'], $w['service_list'])),
                'vlan' => $w['vlan_id'],
                'vlan_priority' => $w['vlan_priority'],
                'service_list' => $req->serviceType === ServiceType::Pppoe ? 'INTERNET' : $w['service_list'],
                'nat' => $req->serviceType === ServiceType::Pppoe ? ($w['nat_enabled'] ?? true) : null,
                'pppoe_username' => $req->serviceType === ServiceType::Pppoe ? $w['pppoe_username'] : null,
            ],
            'ssids' => array_map(fn ($s) => [
                'index' => $s['index'], 'name' => $s['ssid_name'], 'security' => $s['security'],
                'password_changed' => $s['password'] !== null, 'enabled' => $s['enabled'], 'hidden' => $s['hidden'],
            ], $req->ssids),
            'binding' => [
                'wan_interface' => $this->bindingIncludesWan($ctx) ? WANMapper::objectName($wan->slot).'.'.($wan->isNew ? '(baru)' : $wan->i) : null,
                'lan' => $req->binding['lan'],
                'wlan' => $req->binding['wlan'],
            ],
        ];
    }

    public static function same(mixed $expected, mixed $actual): bool
    {
        if (is_bool($expected)) {
            return $expected === in_array(strtolower((string) $actual), ['1', 'true'], true);
        }
        if (is_int($expected)) {
            return is_numeric($actual) && (int) $actual === $expected;
        }

        return trim((string) $expected) === trim((string) $actual);
    }
}

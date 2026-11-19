<?php

namespace Tests\Feature;

use App\Enums\Vendor;
use App\Models\OntDevice;
use App\Models\OntModel;
use App\Services\GenieACS\ParameterTree;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\ONTDetector;
use App\Services\ONT\ONTManager;
use App\Services\ONT\Validators\ONTValidator;
use App\Services\Provisioning\ConfigurationRequest;
use App\Services\Provisioning\ProvisioningPlan;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesGenieAcs;
use Tests\TestCase;

class DriverPlanTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OntModelSeeder::class);
    }

    private function plan(array $input, ?array $doc = null): array
    {
        $doc ??= $this->fiberhomeDoc();
        $tree = ParameterTree::fromDocument($doc);
        $device = OntDevice::firstOrCreate(['genieacs_id' => $tree->id()], ['serial_number' => $tree->deviceId()['serial_number']]);
        $ctx = DeviceContext::build($device, $tree, (new ONTDetector)->detect($tree));
        $plan = app(ONTManager::class)->driverFor($ctx->detection)->buildPlan($ctx, ConfigurationRequest::fromArray($input));

        return [$plan, $ctx];
    }

    private function input(array $wan = [], array $extra = []): array
    {
        return array_replace_recursive([
            'service_type' => 'bridge',
            'wan' => ['mode' => 'new', 'slot' => 'ip', 'vlan_id' => 300, 'service_list' => 'INTERNET'],
            'ssids' => [],
            'binding' => ['lan' => [], 'wlan' => []],
        ], ['wan' => $wan], $extra);
    }

    private function values(ProvisioningPlan $plan): array
    {
        return collect($plan->allValues())->mapWithKeys(fn ($v) => [$v['key'] => $v['value']])->all();
    }

    public function test_fiberhome_bridge_on_wan_ip_creates_new_wan_with_vlan_and_binding(): void
    {
        [$plan, $ctx] = $this->plan($this->input(['service_list' => 'Other'], ['binding' => ['lan' => [1, 2], 'wlan' => [1, 5]]]));

        $this->assertSame([], $plan->errors);
        $this->assertSame(['add_object', 'add_object'], array_slice(array_column($plan->steps, 'type'), 0, 2));
        $this->assertSame('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.#WCD#.WANIPConnection', $plan->steps[1]['object']);

        $v = $this->values($plan);
        $this->assertSame('IP_Bridged', $v['wan.ip.connection_type']);
        $this->assertSame('OTHER', $v['wan.ip.service_list']);
        $this->assertTrue($v['wan.ip.vlan_enable']);
        $this->assertSame(300, $v['wan.ip.vlan_id']);
        $this->assertSame(
            'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.2,'
            .'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.5',
            $v['wan.ip.binding'],
        );
        // Format binding FiberHome belum diverifikasi -> produksi harus menolak
        $this->assertContains('wan.ip.binding', $plan->unverifiedKeys());
        $this->assertNotEmpty(ONTValidator::productionBlockers($ctx, $plan));
    }

    public function test_fiberhome_auto_wan_name_is_not_sent(): void
    {
        // Terbukti di ONT asli: HG6145D2 mengabaikan Name dan membuat "2_INTERNET_B_VID_300" sendiri.
        [$plan] = $this->plan($this->input(['name' => 'NAMA_SAYA']));
        $this->assertArrayNotHasKey('wan.ip.name', $this->values($plan));
        $this->assertNotEmpty(array_filter($plan->warnings, fn ($w) => str_contains($w, 'nama WAN otomatis')));
        $this->assertStringContainsString('otomatis oleh ONT', $plan->summary['wan']['name']);
    }

    public function test_bridge_on_wan_ppp_uses_pppoe_bridged(): void
    {
        [$plan] = $this->plan($this->input(['slot' => 'ppp']));
        $this->assertSame('PPPoE_Bridged', $this->values($plan)['wan.ppp.connection_type']);
    }

    public function test_pppoe_plan_masks_password_and_forces_ppp_slot(): void
    {
        [$plan] = $this->plan($this->input(['slot' => 'ppp', 'pppoe_username' => 'pelanggan1', 'pppoe_password' => 'RahasiaPPP'], ['service_type' => 'pppoe']));
        $this->assertSame([], $plan->errors);
        $v = $this->values($plan);
        $this->assertSame('IP_Routed', $v['wan.ppp.connection_type']);
        $this->assertSame('INTERNET', $v['wan.ppp.service_list']);
        $this->assertTrue($v['wan.ppp.nat_enabled']);
        $this->assertSame('pelanggan1', $v['wan.ppp.username']);
        $this->assertStringNotContainsString('RahasiaPPP', json_encode($plan->toArray()));

        [$wrongSlot] = $this->plan($this->input(['slot' => 'ip', 'pppoe_username' => 'u', 'pppoe_password' => 'p'], ['service_type' => 'pppoe']));
        $this->assertNotEmpty(array_filter($wrongSlot->errors, fn ($e) => str_contains($e, 'slot WAN PPP')));
    }

    public function test_vlan_already_used_and_management_wan_are_blocked(): void
    {
        // WAN TR069 asli memakai VLAN 100
        [$plan] = $this->plan($this->input(['vlan_id' => 100]));
        $this->assertNotEmpty(array_filter($plan->errors, fn ($e) => str_contains($e, 'VLAN 100 sudah dipakai')));

        [$plan] = $this->plan($this->input(['mode' => 'existing', 'existing_path' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1', 'vlan_id' => 100], ['confirm_overwrite' => true]));
        $this->assertNotEmpty(array_filter($plan->errors, fn ($e) => str_contains($e, 'WAN manajemen')));
    }

    public function test_ssid_and_interface_checks_use_real_device_capabilities(): void
    {
        [$plan] = $this->plan($this->input([], [
            'binding' => ['lan' => [5], 'wlan' => []],
            'ssids' => [['index' => 9, 'ssid_name' => 'X'], ['index' => 1, 'enabled' => false], ['index' => 2, 'ssid_name' => 'TAMU', 'security' => 'None']],
        ]));
        $errors = implode(' | ', $plan->errors);
        $this->assertStringContainsString('LAN 5 tidak tersedia', $errors);
        $this->assertStringContainsString('SSID 9 tidak tersedia', $errors);
        $this->assertStringContainsString('SSID 1 sedang aktif', $errors);

        $v = collect($plan->allValues())->keyBy('path');
        $this->assertSame('None', $v['InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.BeaconType']['value']);
        $this->assertSame('TAMU', $v['InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID']['value']);
    }

    public function test_wpa_ssid_sets_beacon_auth_and_secret_passphrase(): void
    {
        [$plan] = $this->plan($this->input([], ['ssids' => [['index' => 1, 'ssid_name' => 'WIFI-RUMAH', 'security' => 'WPAand11i', 'password' => 'wifiaman123']]]));
        $this->assertSame([], $plan->errors);
        $v = collect($plan->allValues())->keyBy('key');
        $this->assertSame('WPAand11i', $v['ssid.beacon_type']['value']);
        $this->assertSame('PSKAuthentication', $v['ssid.auth_mode']['value']);
        $this->assertSame('PSKAuthentication', $v['ssid.wpa_auth_mode']['value']);
        $this->assertTrue($v['ssid.passphrase']['secret']);
        $this->assertStringNotContainsString('wifiaman123', json_encode($plan->toArray()));
    }

    private function zteDoc(string $pc): array
    {
        return $this->fiberhomeDoc(['_id' => "F42D06-{$pc}-ZTEGD4AAA90A", '_deviceId' => ['_Manufacturer' => 'ZTE', '_OUI' => 'F42D06', '_ProductClass' => $pc, '_SerialNumber' => 'ZTEGD4AAA90A']]);
    }

    public function test_zte_f670l_binding_includes_wan_interface(): void
    {
        $model = OntModel::where(['vendor' => Vendor::ZTE, 'product_class' => 'F670L'])->first();
        // Tanpa mapping binding -> harus error, bukan menebak
        $model->parameterMappings()->where('key', 'like', 'wan.%.binding')->delete();
        [$plan] = $this->plan($this->input([], ['binding' => ['lan' => [1], 'wlan' => [1]]]), $this->zteDoc('F670L'));
        $this->assertNotEmpty(array_filter($plan->errors, fn ($e) => str_contains($e, 'wan.ip.binding')));

        foreach (['ip' => 'WANIPConnection', 'ppp' => 'WANPPPConnection'] as $slot => $obj) {
            $model->parameterMappings()->create(['key' => "wan.{$slot}.binding", 'path_template' => "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}.{$obj}.{i}.X_ZTE-COM_LanInterface", 'value_type' => 'xsd:string']);
        }
        OntDevice::query()->delete();

        [$plan] = $this->plan($this->input([], ['binding' => ['lan' => [1, 2], 'wlan' => [1]]]), $this->zteDoc('F670L'));
        $binding = collect($plan->allValues())->firstWhere('key', 'wan.ip.binding')['value'];
        // ZTE: WAN baru ditambahkan di WANConnectionDevice yang sudah ada (.1), instance baru #I#
        $this->assertStringStartsWith('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.#I#,', $binding);
        $this->assertSame(['add_object'], array_values(array_unique(array_column(array_filter($plan->steps, fn ($s) => $s['type'] === 'add_object'), 'type'))));
        $this->assertCount(1, array_filter($plan->steps, fn ($s) => $s['type'] === 'add_object'));
        $this->assertStringEndsWith('LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.2,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1', $binding);
        $this->assertSame('ZTE F670L', $plan->summary['binding']['wan_interface'] ? 'ZTE F670L' : null);
        OntDevice::query()->delete();

        [$ppp] = $this->plan($this->input(['slot' => 'ppp'], ['binding' => ['lan' => [1], 'wlan' => []]]), $this->zteDoc('F670L'));
        $this->assertStringStartsWith('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.#I#,', collect($ppp->allValues())->firstWhere('key', 'wan.ppp.binding')['value']);
    }

    public function test_zte_f609_and_fiberhome_do_not_include_wan_in_binding(): void
    {
        // F609 memakai X_ZTE-COM_LanInterface (dikonfirmasi ada di ONT asli) tanpa path WAN.

        [$plan] = $this->plan($this->input([], ['binding' => ['lan' => [1], 'wlan' => []]]), $this->zteDoc('F609'));
        $this->assertSame('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1', collect($plan->allValues())->firstWhere('key', 'wan.ip.binding')['value']);
        OntDevice::query()->delete();

        [$fh] = $this->plan($this->input([], ['binding' => ['lan' => [1], 'wlan' => []]]));
        $this->assertSame('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1', collect($fh->allValues())->firstWhere('key', 'wan.ip.binding')['value']);
    }

    public function test_huawei_binding_uses_flags_per_port(): void
    {
        $doc = $this->fiberhomeDoc(['_id' => '00259E-HS8145C5-485754439C31B89F', '_deviceId' => ['_Manufacturer' => 'Huawei Technologies Co., Ltd', '_OUI' => '00259E', '_ProductClass' => 'HS8145C5', '_SerialNumber' => '485754439C31B89F']]);
        [$plan] = $this->plan($this->input(['slot' => 'ppp'], ['binding' => ['lan' => [1, 3], 'wlan' => [2]]]), $doc);

        $v = $this->values($plan);
        $this->assertTrue($v['wan.ppp.bind_lan1']);
        $this->assertArrayNotHasKey('wan.ppp.bind_lan2', $v, 'WAN baru: flag false tidak dikirim (default sudah off)');
        $this->assertTrue($v['wan.ppp.bind_lan3']);
        $this->assertTrue($v['wan.ppp.bind_ssid2']);
        $this->assertArrayNotHasKey('wan.ppp.bind_ssid1', $v);
        $this->assertTrue(collect($plan->steps)->where('type', 'set_values')->filter(fn ($s) => str_contains($s['description'], 'flag per port'))->every(fn ($s) => ($s['optional'] ?? false) && count($s['values']) === 1), 'satu port per langkah, opsional');
        $this->assertArrayNotHasKey('wan.ppp.binding', $v);
        $this->assertSame('X_HW_VLAN', substr(collect($plan->allValues())->firstWhere('key', 'wan.ppp.vlan_id')['path'], -9));
        OntDevice::query()->delete();

        [$bad] = $this->plan($this->input(['slot' => 'ppp'], ['binding' => ['lan' => [], 'wlan' => [5]]]), $doc);
        $this->assertNotEmpty(array_filter($bad->errors, fn ($e) => str_contains($e, 'SSID 1-4')));
    }

    public function test_unknown_model_is_rejected_before_any_step(): void
    {
        [$plan] = $this->plan($this->input(), $this->fiberhomeDoc(['_deviceId' => ['_ProductClass' => 'HG9999']]));
        $this->assertSame([], $plan->steps);
        $this->assertStringContainsString('Unsupported Model', $plan->errors[0]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Pppoe\CustomerOntProvisioner;
use Database\Seeders\OntModelSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeMikrotik;
use Tests\Support\FakesGenieAcs;
use Tests\Support\StatefulGenieAcs;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    private FakeMikrotik $mikrotik;

    private User $operator;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([OntModelSeeder::class, TemplateSeeder::class]);
        $this->useFakeAcsSetting();
        config(['ontconfig.task_poll_interval_ms' => 0, 'ontconfig.task_timeout_seconds' => 1]);
        $this->mikrotik = new FakeMikrotik;
        $this->app->instance(MikrotikConnector::class, $this->mikrotik);
        $this->operator = User::factory()->teknisi()->create();
        $this->router = Router::factory()->create();
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return array_merge([
            'router_id' => $this->router->id, 'full_name' => 'Budi Santoso', 'phone' => '081234567890',
            'pppoe_username' => 'budisantoso@snet', 'pppoe_password' => '12345678', 'profile' => '10Mbps',
            'monthly_price' => 150000, 'due_day' => 10, 'status' => 'active', 'ont_sn' => 'fhtt9d34cf80', 'ont_vlan' => 200,
        ], $override);
    }

    public function test_create_customer_writes_mikrotik_radius_and_queues_ont_config(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        // VLAN 100 sudah dipakai WAN TR069 di ONT contoh -> hotspot pakai VLAN lain.
        $this->pppoeWithHotspot(300);

        $this->actingAs($this->operator)->post('/customers', $this->form(['push_ont' => 1]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = PppoeCustomer::where('pppoe_username', 'budisantoso@snet')->firstOrFail();
        $this->assertSame('FHTT9D34CF80', $c->ont_sn);
        $this->assertSame('S.NET - Budi', $c->ont_wifi_ssid);
        $this->assertSame('12345678', $c->ont_wifi_pass, 'password WiFi default = password PPPoE');
        $this->assertSame('budisantoso@snet', $c->portal_username);

        $this->assertSame(['name' => 'budisantoso@snet', 'service' => 'pppoe', 'profile' => '10Mbps', 'disabled' => 'no', 'password' => '12345678'], $this->mikrotik->secrets['budisantoso@snet']);
        $this->assertDatabaseHas('radcheck', ['username' => 'budisantoso@snet', 'attribute' => 'Cleartext-Password', 'value' => '12345678']);
        $this->assertDatabaseHas('radusergroup', ['username' => 'budisantoso@snet', 'groupname' => '10Mbps']);

        // PPPoE lalu Hotspot lewat mesin provisioning (mode simulasi), VLAN PPPoE dari data pelanggan.
        $jobs = ConfigurationJob::orderBy('id')->get();
        $this->assertSame([ServiceType::Pppoe, ServiceType::Bridge], $jobs->pluck('service_type')->all());
        $this->assertSame(200, $jobs[0]->request_payload['wan']['vlan_id']);
        $this->assertSame('budisantoso@snet', $jobs[0]->request_payload['wan']['pppoe_username']);
        $this->assertSame('success', $jobs[0]->status->value);
        $this->assertStringNotContainsString('12345678', json_encode($jobs->pluck('request_payload')));

        // Hotspot dari seksi template PPPoE: SSID 2 + LAN 2, tidak menyentuh SSID 1/LAN 1 milik PPPoE.
        $this->assertSame(['lan' => [2], 'wlan' => [2]], $jobs[1]->request_payload['binding']);
        $this->assertSame([1], $jobs[0]->request_payload['binding']['wlan']);
        $this->assertSame('S.NET @Hotspot', $jobs[1]->request_payload['ssids'][0]['ssid_name']);
        $this->assertSame(300, $jobs[1]->request_payload['wan']['vlan_id']);
    }

    public function test_pppoe_template_without_hotspot_section_pushes_pppoe_only(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        // VLAN PPPoE dikosongkan di form = ikut VLAN template PPPoE (PPPoE HOME: 200).
        $this->actingAs($this->operator)->post('/customers', $this->form(['push_ont' => 1, 'ont_vlan' => null]))->assertSessionHasNoErrors();
        $this->assertSame([ServiceType::Pppoe], ConfigurationJob::pluck('service_type')->all());
        $this->assertSame(200, ConfigurationJob::first()->request_payload['wan']['vlan_id']);
    }

    /** Template PPPoE yang dipakai pelanggan (PPPoE HOME: LAN 1 & SSID 1) diberi seksi hotspot SSID 2 + LAN 2. */
    private function pppoeWithHotspot(int $vlan): void
    {
        $pppoe = ConfigurationTemplate::where('name', 'PPPoE HOME')->firstOrFail();
        $pppoe->update(['is_default' => true, 'payload' => $pppoe->payload + ['hotspot' => [
            'wan' => ['slot' => 'ip', 'vlan_id' => $vlan, 'service_list' => 'INTERNET', 'vlan_priority' => null, 'name' => null],
            'binding' => ['lan' => [2], 'wlan' => [2]], 'ssids' => [['index' => 2, 'ssid_name' => 'S.NET @Hotspot']],
            'wifi' => ['security' => 'open', 'key_enc' => null],
        ]]]);
    }

    public function test_list_shows_online_from_mikrotik_and_huawei_ont_by_hex_serial(): void
    {
        // Sesi dari secret lokal MikroTik (tidak ada di radacct) dan ONT Huawei yang di ACS ber-SN hex.
        $customer = PppoeCustomer::factory()->for($this->router)->create(['pppoe_username' => 'odegoa@snet', 'ont_sn' => 'HWTC79FC699D']);
        $this->router->update(['status' => 'active']);
        $this->mikrotik->active = ['odegoa@snet'];
        Http::fake(['*/devices/*' => Http::response([['_id' => 'x', '_deviceId' => ['_SerialNumber' => '4857544379FC699D', '_OUI' => '00259E', '_ProductClass' => 'HG8245H5', '_Manufacturer' => 'Huawei'], '_lastInform' => now()->subMinute()->toIso8601String()]])]);

        // Cache database seperti produksi (serializable_classes = false): muat dua kali, yang kedua dari cache.
        config(['cache.default' => 'database']);
        foreach ([1, 2] as $load) {
            $this->actingAs($this->operator)->get('/customers')->assertOk()
                ->assertSee('10.10.0.2')->assertSee('ONT Online')->assertDontSee('ONT Offline');
        }
        $this->assertSame('HWTC79FC699D', $customer->ont_sn);
    }

    public function test_single_add_page_passes_sn_to_rumahan_or_hotspot(): void
    {
        foreach (['/tambah-pelanggan', '/lapangan/tambah'] as $url) {
            $this->actingAs($this->operator)->get($url)->assertOk()
                ->assertSee('Pelanggan Rumahan (PPPoE)')->assertSee('Hotspot saja')
                ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        }
        // Langkah berikutnya menerima SN dari halaman pilih jenis.
        $this->actingAs($this->operator)->get('/customers/create?sn=hwtc79fc699d')->assertOk()->assertSee('value="HWTC79FC699D"', false);
        $this->actingAs($this->operator)->get('/lapangan/pelanggan/baru?sn=HWTC79FC699D')->assertOk()->assertSee('value="HWTC79FC699D"', false);
    }

    public function test_push_tags_ont_with_customer_name_replacing_old_tags(): void
    {
        $doc = $this->fiberhomeDoc();
        $doc['_tags'] = ['Pemilik Lama'];
        $acs = (new StatefulGenieAcs($doc))->fake();
        $this->pppoeWithHotspot(300);

        $this->actingAs($this->operator)->post('/customers', $this->form(['push_ont' => 1]))->assertSessionHasNoErrors();

        $this->assertSame(['Budi Santoso'], $acs->doc['_tags'], 'nama pelanggan jadi tag/komentar ONT; tag lama diganti');
    }

    public function test_locate_tells_which_genieacs_has_the_serial(): void
    {
        Http::fake(['*/devices/*' => Http::response([['_id' => 'x', '_deviceId' => ['_SerialNumber' => '4857544379FC699D', '_OUI' => '00259E', '_ProductClass' => 'HG8245H5', '_Manufacturer' => 'Huawei'], '_lastInform' => now()->toIso8601String()]])]);

        $found = $this->actingAs($this->operator)->postJson('/api/ont/locate', ['serial_number' => 'hwtc79fc699d'])->assertOk()->json('found');
        $this->assertCount(1, $found);
        $this->assertTrue($found[0]['online']);
        $this->assertSame('Huawei HG8245H5', $found[0]['product']);

        Http::fake(['*/devices/*' => Http::response([])]);
        $this->actingAs($this->operator)->postJson('/api/ont/locate', ['serial_number' => 'ZTEG00000000'])->assertOk()->assertJson(['found' => []]);
    }

    public function test_conflicting_hotspot_is_reported_not_silently_dropped(): void
    {
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $this->pppoeWithHotspot(100); // VLAN 100 = bentrok dengan WAN TR069 di ONT contoh

        $this->actingAs($this->operator)->post('/customers', $this->form(['push_ont' => 1]))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Hotspot tidak diantrekan') && str_contains($m, 'VLAN 100'));
        $this->assertSame(1, ConfigurationJob::count());
    }

    public function test_hotspot_template_fixed_ssid_names_and_name_placeholder(): void
    {
        $customer = PppoeCustomer::factory()->for($this->router)->create(['full_name' => 'Siti Aminah', 'ont_wifi_ssid' => 'Rumah Siti', 'ont_wifi_pass' => 'wifisiti99']);
        $hotspot = ConfigurationTemplate::create(['name' => 'HS', 'service_type' => 'bridge', 'payload' => [
            'service_type' => 'bridge', 'wan' => ['slot' => 'ip', 'vlan_id' => 300, 'service_list' => 'INTERNET'],
            'binding' => ['lan' => [], 'wlan' => [2]], 'ssids' => [['index' => 2, 'ssid_name' => 'S.NET @Hotspot {nama}']],
        ]]);
        $pppoe = ConfigurationTemplate::where('service_type', 'pppoe')->firstOrFail();

        $ont = app(CustomerOntProvisioner::class);
        $hs = $ont->buildInput($customer, $hotspot, null);
        $this->assertSame([['index' => 2, 'ssid_name' => 'S.NET @Hotspot Siti', 'password' => null, 'security' => null, 'enabled' => true, 'hidden' => null]], $hs['ssids']);
        $this->assertSame(300, $hs['wan']['vlan_id']);

        // Nama tetap lama di template PPPoE diabaikan: WiFi PPPoE selalu dari form pelanggan.
        $pppoe->payload = $pppoe->payload + ['ssids' => [['index' => 1, 'ssid_name' => 'NAMA TEMPLATE']]];
        $pp = $ont->buildInput($customer, $pppoe, 'pw');
        $this->assertSame('Rumah Siti', $pp['ssids'][0]['ssid_name']);
        $this->assertSame('wifisiti99', $pp['ssids'][0]['password']);
        $this->assertSame('11i', $pp['ssids'][0]['security']);
        $this->assertSame('ppp', $pp['wan']['slot']);
    }

    public function test_existing_mikrotik_secret_is_not_overwritten(): void
    {
        $this->mikrotik->secrets['budisantoso@snet'] = ['name' => 'budisantoso@snet', 'password' => 'lama'];

        $this->actingAs($this->operator)->post('/customers', $this->form())->assertSessionHasErrors('router_id');

        $this->assertSame('lama', $this->mikrotik->secrets['budisantoso@snet']['password']);
        $this->assertDatabaseCount('pppoe_customers', 0);
    }

    public function test_wifi_password_must_be_wpa2_length(): void
    {
        $this->actingAs($this->operator)->post('/customers', $this->form(['ont_wifi_pass' => '12345']))->assertSessionHasErrors('ont_wifi_pass');
    }

    public function test_welcome_whatsapp_only_when_checked(): void
    {
        Http::fake(['127.0.0.1:3000/*' => Http::response(['success' => true])]);

        $this->actingAs($this->operator)->post('/customers', $this->form(['ont_sn' => '']))->assertRedirect();
        Http::assertNothingSent();

        $this->actingAs($this->operator)->post('/customers', $this->form(['ont_sn' => '', 'pppoe_username' => 'ani@snet', 'full_name' => 'Ani', 'send_welcome' => 1]))->assertRedirect();
        Http::assertSent(fn ($r) => $r['phone'] === '6281234567890' && str_contains($r['message'], 'ani@snet'));
        $this->assertDatabaseHas('wa_logs', ['message_type' => 'welcome_customer', 'status' => 'success']);
    }

    public function test_isolate_and_reactivate_switch_radius_group_and_kick_session(): void
    {
        $c = PppoeCustomer::factory()->for($this->router)->create();
        $this->mikrotik->secrets[$c->pppoe_username] = ['name' => $c->pppoe_username, 'profile' => '10Mbps'];
        $this->mikrotik->active = [$c->pppoe_username];

        $this->actingAs($this->operator)->post("/customers/{$c->id}/status", ['status' => 'isolated'])->assertRedirect();
        $this->assertSame('isolir', $this->mikrotik->secrets[$c->pppoe_username]['profile']);
        $this->assertSame([], $this->mikrotik->active);
        $this->assertSame('isolir', DB::table('radusergroup')->where('username', $c->pppoe_username)->value('groupname'));

        $this->actingAs($this->operator)->post("/customers/{$c->id}/status", ['status' => 'active'])->assertRedirect();
        $this->assertSame('10Mbps', $this->mikrotik->secrets[$c->pppoe_username]['profile']);
        $this->assertNull($c->fresh()->isolated_at);
    }

    public function test_rename_moves_secret_and_radius_rows(): void
    {
        $c = PppoeCustomer::factory()->for($this->router)->create(['pppoe_username' => 'lama@snet']);
        $this->mikrotik->secrets['lama@snet'] = ['name' => 'lama@snet', 'password' => 'pw-lama1', 'profile' => '10Mbps'];
        DB::table('radcheck')->insert(['username' => 'lama@snet', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'pw-lama1']);

        $this->actingAs($this->operator)->put("/customers/{$c->id}", $this->form(['pppoe_username' => 'baru@snet', 'pppoe_password' => '', 'ont_sn' => '']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('lama@snet', $this->mikrotik->secrets);
        $this->assertArrayHasKey('baru@snet', $this->mikrotik->secrets);
        $this->assertDatabaseMissing('radcheck', ['username' => 'lama@snet']);
        $this->assertDatabaseHas('radcheck', ['username' => 'baru@snet', 'attribute' => 'Cleartext-Password', 'value' => 'pw-lama1']);
    }

    public function test_sync_from_mikrotik_imports_secrets_with_radius_password(): void
    {
        PppoeCustomer::factory()->for($this->router)->create(['pppoe_username' => 'ada@snet', 'profile' => '10Mbps', 'monthly_price' => 175000]);
        $this->mikrotik->secrets = [
            'ada@snet' => ['name' => 'ada@snet', 'profile' => '20Mbps', 'password' => 'p1'],
            'baru@snet' => ['name' => 'baru@snet', 'profile' => '10Mbps', 'password' => 'p2', 'comment' => 'Pak Baru'],
            'telat@snet' => ['name' => 'telat@snet', 'profile' => 'isolir', 'password' => 'p3'],
        ];
        $this->actingAs(User::factory()->admin()->create())->post('/customers/sync-mikrotik', ['router_id' => $this->router->id])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '2 pelanggan baru, 1 diperbarui'));

        $baru = PppoeCustomer::where('pppoe_username', 'baru@snet')->firstOrFail();
        $this->assertSame(['Pak Baru', 175000], [$baru->full_name, $baru->monthly_price]);
        $this->assertSame('isolated', PppoeCustomer::where('pppoe_username', 'telat@snet')->value('status'));
        $this->assertSame('20Mbps', PppoeCustomer::where('pppoe_username', 'ada@snet')->value('profile'));
        $this->assertDatabaseHas('radcheck', ['username' => 'baru@snet', 'attribute' => 'Cleartext-Password', 'value' => 'p2']);
    }

    public function test_viewer_cannot_manage_customers_and_pages_render(): void
    {
        $c = PppoeCustomer::factory()->for($this->router)->create();
        $this->actingAs(User::factory()->viewer()->create())->get('/customers')->assertForbidden();

        $this->actingAs($this->operator)->get('/customers')->assertOk()->assertSee($c->full_name);
        $this->actingAs($this->operator)->get('/customers/create')->assertOk()->assertSee('10Mbps');
        $this->actingAs($this->operator)->get("/customers/{$c->id}")->assertOk()->assertSee('Config ONT');
        $this->actingAs($this->operator)->get("/auto-config?customer={$c->id}&service=pppoe")->assertOk()->assertSee($c->pppoe_username);
    }
}

<?php

namespace Tests\Feature;

use App\Models\OntDevice;
use App\Models\User;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesGenieAcs;
use Tests\TestCase;

class OntApiTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OntModelSeeder::class);
        $this->useFakeAcsSetting();
    }

    private function teknisi(): User
    {
        return User::factory()->teknisi()->create();
    }

    public function test_search_found_creates_local_cache(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => ' fhtt9d34cf80 '])
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('devices.0.serial_number', 'FHTT9D34CF80')
            ->assertJsonPath('devices.0.detection.vendor', 'fiberhome')
            ->assertJsonPath('devices.0.detection.simulation_only', true);

        $this->assertDatabaseHas('ont_devices', ['serial_number' => 'FHTT9D34CF80', 'genieacs_id' => '000AC2-HG6145D2-FHTT9D34CF80']);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $query = json_decode($q['query'] ?? '{}', true);

            return in_array('FHTT9D34CF80', $query['_deviceId._SerialNumber']['$in'] ?? [], true);
        });
    }

    public function test_last_inform_utc_is_stored_correctly_in_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
        $this->fakeAcs([$this->fiberhomeDoc(['_lastInform' => now('UTC')->subSeconds(30)->format('Y-m-d\\TH:i:s.v\\Z')])]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => 'FHTT9D34CF80'])->assertOk();

        $device = OntDevice::firstOrFail();
        $this->assertTrue($device->informedRecently(), 'Inform 30 detik lalu harus terbaca "baru", bukan selisih 7 jam.');
        $this->assertEqualsWithDelta(30, $device->last_inform_at->diffInSeconds(now(), true), 5);
    }

    public function test_search_not_found(): void
    {
        $this->fakeAcs([]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => 'ZTEG00000000'])
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('label', 'Device Not Found');
    }

    public function test_search_multiple_requires_choice(): void
    {
        $a = $this->fiberhomeDoc();
        $b = $this->fiberhomeDoc(['_id' => '000AC3-HG6145D2-FHTT9D34CF80', '_deviceId' => ['_OUI' => '000AC3']]);
        $this->fakeAcs([$a, $b]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => 'FHTT9D34CF80'])
            ->assertOk()
            ->assertJsonPath('status', 'multiple')
            ->assertJsonCount(2, 'devices');
    }

    public function test_result_with_different_serial_is_discarded(): void
    {
        // GenieACS (atau proxy) mengembalikan device yang SN-nya tidak cocok -> harus dibuang.
        Http::fake(['*' => Http::response([$this->identity($this->fiberhomeDoc(['_deviceId' => ['_SerialNumber' => 'LAINLAIN1234']]))], 200)]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => 'FHTT9D34CF80'])
            ->assertOk()
            ->assertJsonPath('status', 'not_found');
    }

    public function test_huawei_sticker_sn_searches_hex_form_too(): void
    {
        $doc = $this->fiberhomeDoc(['_id' => '00259E-HS8145C5-485754439C31B89F', '_deviceId' => [
            '_Manufacturer' => 'Huawei Technologies Co., Ltd', '_OUI' => '00259E', '_ProductClass' => 'HS8145C5', '_SerialNumber' => '485754439C31B89F',
        ]]);
        $this->fakeAcs([$doc]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => 'HWTC9C31B89F'])
            ->assertOk()
            ->assertJsonPath('devices.0.serial_number', '485754439C31B89F')
            ->assertJsonPath('devices.0.detection.vendor', 'huawei');
    }

    public function test_search_validation_and_viewer_access(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()]);
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->postJson('/api/ont/search', ['serial_number' => '$where'])
            ->assertUnprocessable()->assertJsonValidationErrors('serial_number');
        $this->actingAs($viewer)->postJson('/api/ont/search', ['serial_number' => 'FHTT9D34CF80'])->assertOk();
    }

    public function test_genieacs_down_returns_clear_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->actingAs($this->teknisi())->postJson('/api/ont/search', ['serial_number' => 'FHTT9D34CF80'])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'GENIEACS_UNREACHABLE')
            ->assertJsonMissingPath('exception');
    }

    private function cachedDevice(): OntDevice
    {
        return OntDevice::create(['genieacs_id' => '000AC2-HG6145D2-FHTT9D34CF80', 'serial_number' => 'FHTT9D34CF80']);
    }

    public function test_detail_returns_info_without_secrets(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()]);
        $device = $this->cachedDevice();

        $res = $this->actingAs($this->teknisi())->getJson("/api/ont/{$device->id}")
            ->assertOk()
            ->assertJsonPath('info.counts.ssid', 8)
            ->assertJsonPath('info.software_version', 'RP4313')
            ->assertJsonPath('detection.model_name', 'HG6145D2');

        $this->assertStringNotContainsString('FIXTURE-SECRET', $res->getContent());
        $this->assertSame('RP4313', $device->fresh()->software_version);
        $this->assertSame(8, $device->fresh()->parameters_snapshot['counts']['ssid']);
    }

    public function test_online_check_ready_when_device_responds(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()], 200);
        $device = $this->cachedDevice();

        $this->actingAs($this->teknisi())->postJson("/api/ont/{$device->id}/check-online")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('online', true)
            ->assertJsonPath('simulation_only', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/devices/000AC2-HG6145D2-FHTT9D34CF80/tasks?connection_request=&timeout=3000')
            && $r['name'] === 'getParameterValues');
        $this->assertTrue($device->fresh()->last_online_result);
    }

    public function test_online_check_offline_cancels_queued_task(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()], 202);
        $device = $this->cachedDevice();

        $this->actingAs($this->teknisi())->postJson("/api/ont/{$device->id}/check-online")
            ->assertOk()
            ->assertJsonPath('status', 'offline')
            ->assertJsonPath('online', false);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/tasks/task-'));
        $this->assertFalse($device->fresh()->last_online_result);
    }

    public function test_unknown_model_is_unsupported_even_when_online(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc(['_deviceId' => ['_ProductClass' => 'HG9999']])], 200);
        $device = $this->cachedDevice();

        $this->actingAs($this->teknisi())->postJson("/api/ont/{$device->id}/check-online")
            ->assertJsonPath('status', 'unsupported')
            ->assertJsonPath('label', 'Unsupported Model');
    }

    public function test_parameters_endpoint_masks_secrets(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()]);
        $device = $this->cachedDevice();

        $res = $this->actingAs(User::factory()->viewer()->create())
            ->getJson("/api/ont/{$device->id}/parameters?prefix=InternetGatewayDevice.LANDevice.1.WLANConfiguration.1")
            ->assertOk();

        $this->assertStringNotContainsString('FIXTURE-SECRET', $res->getContent());
        $this->assertContains('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', array_column($res->json('parameters'), 'path'));
        $this->actingAs(User::factory()->viewer()->create())
            ->getJson("/api/ont/{$device->id}/parameters?prefix=bad\$path")->assertUnprocessable();
    }

    public function test_refresh_requires_configure_permission_and_stops_when_offline(): void
    {
        $this->fakeAcs([$this->fiberhomeDoc()], 202);
        $device = $this->cachedDevice();

        $this->actingAs(User::factory()->viewer()->create())->postJson("/api/ont/{$device->id}/refresh")->assertForbidden();

        $this->actingAs($this->teknisi())->postJson("/api/ont/{$device->id}/refresh")
            ->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonCount(1, 'results');
    }

    public function test_sync_imports_devices(): void
    {
        $zte = $this->fiberhomeDoc(['_id' => 'F42D06-F670L-ZTEGD4AAA90A', '_deviceId' => [
            '_Manufacturer' => 'ZTE', '_OUI' => 'F42D06', '_ProductClass' => 'F670L', '_SerialNumber' => 'ZTEGD4AAA90A',
        ]]);
        $this->fakeAcs([$this->fiberhomeDoc(), $zte]);

        $this->actingAs($this->teknisi())->postJson('/api/ont/sync')->assertOk()->assertJsonPath('synced', 2);
        $this->assertDatabaseHas('ont_devices', ['serial_number' => 'ZTEGD4AAA90A', 'vendor' => 'zte']);
        $this->assertSame(2, OntDevice::count());

        $this->actingAs(User::factory()->viewer()->create())->postJson('/api/ont/sync')->assertForbidden();
    }

    public function test_incomplete_data_after_bootstrap_is_flagged(): void
    {
        $doc = json_decode(file_get_contents(base_path('tests/Fixtures/genieacs/fiberhome_hg6145d2_after_bootstrap.json')), true)[0];
        $this->fakeAcs([$doc]);
        $device = $this->cachedDevice();

        $res = $this->actingAs($this->teknisi())->getJson("/api/ont/{$device->id}")->assertOk();
        $this->assertFalse($res->json('info.completeness.complete'));
        $this->assertNotEmpty($res->json('info.completeness.missing'));
        $this->assertStringNotContainsString('FIXTURE-SECRET', $res->getContent());
    }

    public function test_pages_render(): void
    {
        $device = $this->cachedDevice();
        $user = User::factory()->viewer()->create();

        $this->actingAs($user)->get('/auto-config')->assertOk()->assertSee('SEARCH ONT');
        $this->actingAs($user)->get('/devices')->assertOk()->assertSee('FHTT9D34CF80')->assertDontSee('Sinkronkan dari GenieACS');
        $this->actingAs($this->teknisi())->get('/devices')->assertSee('Sinkronkan dari GenieACS');
        $this->actingAs($user)->get("/devices/{$device->id}")->assertOk()->assertSee('FHTT9D34CF80');
    }
}

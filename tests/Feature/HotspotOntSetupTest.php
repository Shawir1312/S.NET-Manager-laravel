<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\OntDevice;
use App\Models\User;
use App\Services\ONT\HotspotOntSetup;
use Database\Seeders\OntModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesGenieAcs;
use Tests\Support\StatefulGenieAcs;
use Tests\TestCase;

class HotspotOntSetupTest extends TestCase
{
    use FakesGenieAcs, RefreshDatabase;

    private function templateData(array $override = []): array
    {
        return array_merge([
            'name' => 'Hotspot S.NET', 'service_type' => 'bridge', 'slot' => 'ip', 'vlan_id' => 300, 'service_list' => 'INTERNET',
            'lan' => [1, 2], 'wlan' => [1], 'ssid_names' => [1 => 'S.NET - {nama}'], 'is_default' => 1,
        ], $override);
    }

    public function test_template_wifi_security_is_stored_encrypted_and_kept_on_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/templates', $this->templateData(['wifi_security' => 'password', 'wifi_key' => 'rahasia123']))->assertRedirect();
        $t = ConfigurationTemplate::firstOrFail();

        $this->assertStringNotContainsString('rahasia123', json_encode($t->getAttributes()), 'password tidak boleh tersimpan teks biasa');
        $this->assertSame(['11i', 'rahasia123'], $t->hotspotWifi());

        // Edit tanpa mengisi password: password lama tetap dipakai.
        $this->actingAs($admin)->put("/templates/{$t->id}", $this->templateData(['wifi_security' => 'password', 'wifi_key' => '']))->assertRedirect();
        $this->assertSame(['11i', 'rahasia123'], $t->fresh()->hotspotWifi());

        $this->actingAs($admin)->put("/templates/{$t->id}", $this->templateData(['wifi_security' => 'open']))->assertRedirect();
        $this->assertSame(['None', null], $t->fresh()->hotspotWifi());

        // Mode password tanpa password tersimpan ditolak.
        $this->actingAs($admin)->post('/templates', $this->templateData(['name' => 'Baru', 'wifi_security' => 'password']))->assertSessionHasErrors('wifi_key');
    }

    public function test_build_input_follows_template_with_name_placeholder(): void
    {
        $t = ConfigurationTemplate::create(['name' => 'H', 'service_type' => ServiceType::Bridge, 'payload' => [
            'wan' => ['slot' => 'ip', 'vlan_id' => 300, 'service_list' => 'INTERNET'], 'binding' => ['lan' => [1], 'wlan' => [1, 2]],
            'ssids' => [['index' => 1, 'ssid_name' => 'S.NET - {nama}']], 'wifi' => ['security' => 'open'],
        ]]);

        $input = app(HotspotOntSetup::class)->buildInput($t, '  Warung   Bu Ani ');

        $this->assertSame(300, $input['wan']['vlan_id']);
        $this->assertSame(['index' => 1, 'ssid_name' => 'S.NET - Warung Bu Ani', 'password' => null, 'security' => 'None', 'enabled' => true, 'hidden' => null], $input['ssids'][0]);
        $this->assertSame('Warung Bu Ani', $input['ssids'][1]['ssid_name'], 'SSID tanpa nama tetap memakai nama yang diisi');
    }

    public function test_apply_endpoint_runs_simulation_job_with_template(): void
    {
        $this->seed(OntModelSeeder::class);
        $this->useFakeAcsSetting();
        config(['ontconfig.task_poll_interval_ms' => 0, 'ontconfig.task_timeout_seconds' => 1]);
        (new StatefulGenieAcs($this->fiberhomeDoc()))->fake();
        $device = OntDevice::create(['genieacs_id' => '000AC2-HG6145D2-FHTT9D34CF80', 'serial_number' => 'FHTT9D34CF80']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/templates', $this->templateData(['wifi_security' => 'password', 'wifi_key' => 'rahasia123']));
        $tech = User::factory()->teknisi()->create();

        $this->actingAs($tech)->get('/ont-hotspot')->assertOk()->assertSee('Scan QR / Barcode SN')->assertSee('Hotspot S.NET')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $this->actingAs($tech)->get('/dashboard')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->actingAs($tech)->get('/dashboard')->assertOk()->assertSee('Setting ONT Hotspot');

        $res = $this->actingAs($tech)->postJson('/ont-hotspot/apply', ['device_id' => $device->id, 'name' => 'Warung Ani'])
            ->assertStatus(202)->assertJsonPath('is_simulation', true);
        $job = ConfigurationJob::where('uuid', $res->json('id'))->firstOrFail();
        $this->assertSame(ConfigurationTemplate::firstOrFail()->id, $job->configuration_template_id);
        $this->assertStringNotContainsString('rahasia123', json_encode($job->plan), 'password tidak muncul di rencana');

        $this->actingAs($tech)->postJson('/ont-hotspot/apply', ['device_id' => $device->id, 'name' => '<script>'])->assertUnprocessable();
        $this->actingAs(User::factory()->viewer()->create())->postJson('/ont-hotspot/apply', ['device_id' => $device->id, 'name' => 'X'])->assertForbidden();
    }
}

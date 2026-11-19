<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ConfigurationTemplate;
use App\Models\OntModel;
use App\Models\User;
use Database\Seeders\GenieAcsSettingSeeder;
use Database\Seeders\OntModelSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([GenieAcsSettingSeeder::class, OntModelSeeder::class, TemplateSeeder::class]);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_all_pages_render_for_admin(): void
    {
        $model = OntModel::first();
        $template = ConfigurationTemplate::first();
        foreach (['/dashboard', '/auto-config', '/devices', '/lapangan', '/templates', '/templates/create',
            "/templates/{$template->id}/edit", '/models', '/models/create', "/models/{$model->id}/edit", '/history', '/users',
            '/users/create', "/users/{$this->admin->id}/edit", '/system', '/settings/genieacs'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_template_crud_never_stores_password(): void
    {
        $this->actingAs($this->admin)->post('/templates', [
            'name' => 'PPPoE KANTOR', 'service_type' => 'pppoe', 'slot' => 'ppp', 'vlan_id' => 250, 'service_list' => 'INTERNET',
            'lan' => [1, 2], 'wlan' => [1, 2], 'pppoe_password' => 'jangan-disimpan', 'is_default' => 1,
            'ssid_names' => [2 => 'S.NET @Hotspot', 3 => 'tidak di-bind'],
            'hs_enabled' => 1, 'hs_vlan_id' => 300, 'hs_lan' => [3], 'hs_wlan' => [3], 'hs_ssid_names' => [3 => 'S.NET @Hotspot'],
            'hs_wifi_security' => 'password', 'hs_wifi_key' => 'hotspot12345',
        ])->assertRedirect('/templates');

        $t = ConfigurationTemplate::where('name', 'PPPoE KANTOR')->firstOrFail();
        $this->assertTrue($t->is_default);
        // Satu default per jenis layanan: default Hotspot (seeder) tetap ada.
        $this->assertSame(1, ConfigurationTemplate::where('is_default', true)->where('service_type', 'pppoe')->count());
        $this->assertSame(1, ConfigurationTemplate::where('is_default', true)->where('service_type', 'bridge')->count());
        $this->assertSame([['index' => 2, 'ssid_name' => 'S.NET @Hotspot']], $t->payload['ssids']);
        $this->assertStringNotContainsString('jangan-disimpan', json_encode($t->payload));
        $this->assertSame([1, 2], $t->payload['binding']['lan']);
        $this->assertSame(['lan' => [3], 'wlan' => [3]], $t->payload['hotspot']['binding'], 'template PPPoE menyertakan layanan hotspot');
        $this->assertSame([['index' => 3, 'ssid_name' => 'S.NET @Hotspot']], $t->payload['hotspot']['ssids']);
        $this->assertStringNotContainsString('hotspot12345', json_encode($t->payload), 'password hotspot terenkripsi');
        $this->assertSame(['11i', 'hotspot12345'], $t->hotspotPart()->hotspotWifi());

        $this->actingAs($this->admin)->post("/templates/{$t->id}/copy")->assertRedirect();
        $this->assertDatabaseHas('configuration_templates', ['name' => 'PPPoE KANTOR (salinan)', 'is_default' => false]);

        $teknisi = User::factory()->teknisi()->create();
        $this->actingAs($teknisi)->get('/templates')->assertOk()->assertDontSee('Template baru');
        $this->actingAs($teknisi)->post('/templates', ['name' => 'x'])->assertForbidden();
        $this->actingAs($teknisi)->getJson('/api/templates')->assertOk()->assertJsonFragment(['name' => 'HOTSPOT BRIDGE']);
    }

    public function test_pppoe_template_hotspot_section_cannot_share_ports_or_vlan_with_pppoe(): void
    {
        $base = ['name' => 'BENTROK', 'service_type' => 'pppoe', 'slot' => 'ppp', 'vlan_id' => 250, 'service_list' => 'INTERNET',
            'lan' => [1, 2], 'wlan' => [1], 'hs_enabled' => 1];

        $this->actingAs($this->admin)->post('/templates', $base + ['hs_vlan_id' => 250, 'hs_lan' => [2], 'hs_wlan' => [1]])
            ->assertSessionHasErrors(['hs_lan', 'hs_wlan', 'hs_vlan_id']);
        $this->assertDatabaseMissing('configuration_templates', ['name' => 'BENTROK']);
    }

    public function test_user_management_guards(): void
    {
        $this->actingAs($this->admin)->post('/users', [
            'name' => 'Budi', 'email' => 'budi@isp.test', 'role' => 'teknisi', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'is_active' => 1,
        ])->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['email' => 'budi@isp.test', 'role' => 'teknisi']);

        // Tidak bisa menurunkan diri sendiri / menghapus diri sendiri
        $this->actingAs($this->admin)->put("/users/{$this->admin->id}", ['name' => 'A', 'email' => $this->admin->email, 'role' => 'viewer', 'is_active' => 1])
            ->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->delete("/users/{$this->admin->id}")->assertSessionHasErrors('user');

        // Password lemah ditolak
        $this->actingAs($this->admin)->post('/users', ['name' => 'C', 'email' => 'c@isp.test', 'role' => 'viewer', 'password' => 'abc', 'password_confirmation' => 'abc'])
            ->assertSessionHasErrors('password');

        $this->actingAs(User::factory()->teknisi()->create())->get('/users')->assertForbidden();
    }

    public function test_saving_model_form_keeps_existing_capability_extra(): void
    {
        $model = OntModel::where('product_class', 'HG6145D2')->first();
        $model->capability->update(['extra' => ['ssid_band' => ['2.4GHz' => [1]], 'wan_name_auto' => true, 'pppoe_slot' => 'ppp']]);

        $this->actingAs($this->admin)->put("/models/{$model->id}", [
            'vendor' => 'fiberhome', 'model_name' => 'HG6145D2', 'product_class' => 'HG6145D2', 'firmware_pattern' => '*',
            'status' => 'supported', 'binding_method' => 'string_list', 'pppoe_slot' => 'ppp', 'vlan_min' => 1, 'vlan_max' => 4094,
            'wan_name_auto' => 1,
        ])->assertRedirect();

        $extra = $model->capability->fresh()->extra;
        $this->assertTrue($extra['wan_name_auto']);
        $this->assertSame(['2.4GHz' => [1]], $extra['ssid_band'], 'Data extra lain tidak boleh hilang saat form disimpan');
    }

    public function test_model_mapping_editing(): void
    {
        $model = OntModel::where('product_class', 'F670L')->first();
        $this->actingAs($this->admin)->post("/models/{$model->id}/mappings", [
            'key' => 'wan.ip.binding', 'path_template' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}.WANIPConnection.{i}.X_ZTE-COM_LanInterface',
            'value_type' => 'xsd:string', 'verified' => 1, 'source' => 'dicek di ONT ZTEG123',
        ])->assertRedirect();

        $m = $model->parameterMappings()->where('key', 'wan.ip.binding')->first();
        $this->assertSame('wan.ip.binding', AuditLog::where('action', 'model.mapping_saved')->latest('id')->first()->changes['key']);
        $this->assertTrue($m->verified);
        $this->assertNotNull($m->verified_at);

        $this->actingAs($this->admin)->post("/models/{$model->id}/mappings", ['key' => 'x', 'path_template' => 'bad path;drop', 'value_type' => 'xsd:string'])
            ->assertSessionHasErrors('path_template');
        $this->actingAs(User::factory()->teknisi()->create())->get('/models')->assertForbidden();
    }
}

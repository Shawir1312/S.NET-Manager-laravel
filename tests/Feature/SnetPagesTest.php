<?php

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\OntDevice;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use App\Models\WaConfig;
use App\Models\WgRouter;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\WireGuard\WireGuardService;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

/** Semua halaman admin S.NET bisa dirender (menangkap error Blade/route di view yang jarang dibuka). */
class SnetPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return array_map(fn ($p) => [$p], [
            '/dashboard', '/customers', '/customers/create', '/customers/{customer}', '/customers/{customer}/edit', '/customers/{customer}?tab=ont',
            '/payments', '/pppoe-profiles', '/pppoe-settings',
            '/whatsapp?tab=connection', '/whatsapp?tab=config', '/whatsapp?tab=templates', '/whatsapp?tab=inbox', '/whatsapp?tab=logs',
            '/vouchers', '/vouchers/create', '/hotspot-profiles', '/hotspot-profiles/create', '/hotspot-profiles/{profile}/edit',
            '/monitoring/active', '/monitoring/mac', '/ont-monitor',
            '/reports/sales', '/reports/usage', '/reports/penagihan',
            '/routers', '/routers/create', '/routers/{router}/edit',
            '/wireguard', '/wireguard?tab=forwards', '/wireguard?tab=settings', '/wireguard?tab=logs', '/wireguard/create', '/wireguard/{wg}', '/wireguard/{wg}/edit',
            '/settings/general', '/settings/backup', '/settings/audit', '/settings/genieacs', '/settings/genieacs?server=new',
            '/templates', '/templates/create', '/users', '/users/create', '/auto-config', '/devices', '/system',
        ]);
    }

    #[DataProvider('pages')]
    public function test_admin_page_renders(string $path): void
    {
        $this->seed(TemplateSeeder::class);
        $this->app->instance(MikrotikConnector::class, new FakeMikrotik);
        Http::fake(['*' => Http::response(['status' => 'connected'])]);

        $router = Router::factory()->create();
        $customer = PppoeCustomer::factory()->for($router)->create(['ont_sn' => 'FHTT0000AAAA']);
        OntDevice::create(['genieacs_id' => 'X-FHTT0000AAAA', 'serial_number' => 'FHTT0000AAAA', 'last_inform_at' => now()]);
        $profile = HotspotProfile::create(['name' => 'P', 'validity_value' => 1, 'validity_unit' => 'days', 'duration_value' => 0, 'duration_unit' => 'hours', 'price' => 1, 'include_in_sales' => true, 'is_active' => true]);
        $wg = WgRouter::create(['name' => 'Cabang', 'tunnel_ip' => '10.66.66.2', 'listen_port' => 13231] + WireGuardService::keypair());

        $url = strtr($path, ['{customer}' => $customer->id, '{router}' => $router->id, '{profile}' => $profile->id, '{wg}' => $wg->id]);
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    }

    public function test_operator_sees_operational_menu_only(): void
    {
        $op = User::factory()->teknisi()->create();
        $this->actingAs($op)->get('/dashboard')->assertOk()->assertSee('Pelanggan')->assertSee('Generate Voucher')->assertDontSee('WireGuard VPN');
        foreach (['/routers', '/wireguard', '/pppoe-settings', '/whatsapp', '/settings/backup'] as $p) {
            $this->actingAs($op)->get($p)->assertForbidden();
        }
    }

    public function test_whatsapp_config_saves_with_empty_device_id_and_keeps_token(): void
    {
        $admin = User::factory()->admin()->create();
        // WA Web / Fonnte: kolom Device ID tersembunyi dan terkirim kosong.
        $this->actingAs($admin)->put('/whatsapp/config', ['provider' => 'fonnte', 'api_url' => 'https://api.fonnte.com/send', 'api_token' => 'tok-123', 'device_id' => '', 'is_active' => 1])
            ->assertRedirect(route('whatsapp.index', ['tab' => 'config']));
        $this->actingAs($admin)->put('/whatsapp/config', ['provider' => 'waweb', 'api_url' => 'http://127.0.0.1:3000/api/send', 'api_token' => '', 'device_id' => ''])
            ->assertRedirect();

        $cfg = WaConfig::firstOrFail();
        $this->assertSame(['waweb', '', 'tok-123', false], [$cfg->provider, $cfg->device_id, $cfg->api_token, $cfg->is_active]);
    }

    public function test_wireguard_keys_match_rfc7748_vector(): void
    {
        // RFC 7748 §6.1: private key Alice -> public key Alice.
        $priv = base64_encode(hex2bin('77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a'));
        $this->assertSame(bin2hex(base64_decode(WireGuardService::publicKeyOf($priv))), '8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a');
    }
}

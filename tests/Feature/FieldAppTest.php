<?php

namespace Tests\Feature;

use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use Database\Seeders\OntModelSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class FieldAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_pages_use_mobile_layout_and_customer_store_returns_to_field_home(): void
    {
        $this->seed([OntModelSeeder::class, TemplateSeeder::class]);
        $this->app->instance(MikrotikConnector::class, new FakeMikrotik);
        $tech = User::factory()->teknisi()->create();
        $router = Router::factory()->create();

        $this->get('/lapangan')->assertRedirect('/login');
        foreach (['/lapangan', '/lapangan/hotspot', '/lapangan/pelanggan/baru'] as $url) {
            $this->actingAs($tech)->get($url)->assertOk()->assertSee('Lapangan')->assertDontSee('id="sidebar"', false);
        }
        $this->actingAs($tech)->get('/lapangan/hotspot')->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $this->actingAs($tech)->get('/lapangan/pelanggan/baru')->assertSee('name="from_field"', false)->assertSee('field-hide', false)->assertSee('btnScanSn', false);

        $this->actingAs($tech)->post('/customers', [
            'router_id' => $router->id, 'full_name' => 'Rina', 'phone' => '081234567891', 'pppoe_username' => 'rina@snet',
            'pppoe_password' => '12345678', 'profile' => '10Mbps', 'monthly_price' => 100000, 'due_day' => 5, 'status' => 'active',
            'from_field' => 1,
        ])->assertRedirect('/lapangan');
        $this->assertTrue(PppoeCustomer::where('pppoe_username', 'rina@snet')->exists());
    }

    public function test_technician_lands_on_field_app_after_login_admin_on_dashboard(): void
    {
        $tech = User::factory()->teknisi()->create(['password' => 'rahasia123']);
        $this->post('/login', ['email' => $tech->email, 'password' => 'rahasia123'])->assertRedirect('/lapangan');
        $this->post('/logout');

        $admin = User::factory()->admin()->create(['password' => 'rahasia123']);
        $this->post('/login', ['email' => $admin->email, 'password' => 'rahasia123'])->assertRedirect('/dashboard');
    }
}

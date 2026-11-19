<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WgRouter;
use App\Services\Settings\SnetSettings;
use App\Services\WireGuard\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WireGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_derives_public_key_and_tools_and_settings(): void
    {
        $admin = User::factory()->admin()->create();
        $keys = $this->actingAs($admin)->getJson('/wireguard-tools?action=generate_keys')->assertOk()->json();
        $this->assertSame(WireGuardService::publicKeyOf($keys['private_key']), $keys['public_key']);
        $ip = $this->actingAs($admin)->getJson('/wireguard-tools?action=get_next_ip')->json('ip');

        $this->actingAs($admin)->post('/wireguard', ['name' => 'Cabang A', 'tunnel_ip' => $ip, 'listen_port' => 13231, 'private_key' => $keys['private_key']])->assertRedirect();
        $router = WgRouter::firstOrFail();
        $this->assertSame($keys['public_key'], $router->public_key);
        $this->actingAs($admin)->get("/wireguard/{$router->id}")->assertOk()->assertSee($router->tunnel_ip)->assertSee('Skrip Konfigurasi MikroTik');

        // Diagnostik hanya untuk IP tunnel terdaftar; aksi tulis wajib POST.
        $this->actingAs($admin)->getJson('/wireguard-tools?action=ping&ip=8.8.8.8')->assertNotFound();
        $this->actingAs($admin)->getJson('/wireguard-tools?action=sync_all_peers')->assertStatus(405);

        $this->actingAs($admin)->put('/wireguard-settings', ['wg_server_endpoint' => 'vpn.example.test:51820', 'wg_listen_port' => 51820, 'wg_subnet_prefix' => '10.9.9.0/24',
            'wg_interface' => 'wg0', 'wg_remote_port_range' => '20000-58000'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('10.9.9.', app(WireGuardService::class)->config()['wg_subnet_prefix']);
    }

    public function test_mikrotik_script_is_safe_to_paste_repeatedly(): void
    {
        app(SnetSettings::class)->put('wireguard', ['wg_server_pubkey' => str_repeat('A', 43).'=', 'wg_server_endpoint' => 'vpn.example.test:51820']);
        $router = WgRouter::create(['name' => 'Cabang', 'tunnel_ip' => '10.66.66.2', 'listen_port' => 13231, 'lan_subnets' => '192.168.9.0/24'] + WireGuardService::keypair());

        $script = app(WireGuardService::class)->mikrotikScript($router);

        // Bersihkan dulu objek lama milik router ini, lalu buat ulang di interface khusus aplikasi.
        $this->assertStringContainsString('/interface wireguard remove [find private-key="'.$router->private_key.'"]', $script);
        $this->assertStringContainsString('/ip address remove [find address="10.66.66.2/24"]', $script);
        $this->assertStringContainsString('/ip firewall nat remove [find comment~"^S.NET-MGR"]', $script);
        $this->assertStringContainsString('add name='.WireGuardService::MIKROTIK_IFACE.' ', $script);
        $this->assertStringContainsString('dst-address=192.168.9.0/24 action=masquerade', $script);
        $this->assertStringNotContainsString('place-before=0', $script, 'place-before=0 gagal bila daftar firewall kosong');
        $this->assertStringNotContainsString('name=wg-snet ', $script, 'tidak boleh bentrok dengan interface wg-snet lama');
        $this->assertLessThan(strpos($script, '/interface wireguard add'), strpos($script, '/interface wireguard remove'));
    }
}

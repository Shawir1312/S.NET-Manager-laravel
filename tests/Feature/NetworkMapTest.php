<?php

namespace Tests\Feature;

use App\Models\Odp;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Settings\SnetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class NetworkMapTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotik $mikrotik;

    private Router $router;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mikrotik = new FakeMikrotik;
        $this->app->instance(MikrotikConnector::class, $this->mikrotik);
        $this->router = Router::factory()->create(['status' => 'active', 'latitude' => 0.79, 'longitude' => 127.37]);
        $this->tech = User::factory()->teknisi()->create();
    }

    private function customer(string $user, ?Odp $odp, array $extra = []): PppoeCustomer
    {
        return PppoeCustomer::factory()->for($this->router)->create(['pppoe_username' => $user, 'odp_id' => $odp?->id, 'latitude' => 0.791, 'longitude' => 127.371] + $extra);
    }

    public function test_map_data_marks_connected_lines_and_cut_odp(): void
    {
        $odpUp = Odp::factory()->for($this->router)->create(['name' => 'ODP-A']);
        $odpDown = Odp::factory()->for($this->router)->create(['name' => 'ODP-B']);
        $this->customer('online@snet', $odpUp);
        $this->customer('putus1@snet', $odpUp);
        $this->customer('putus2@snet', $odpDown);
        $this->customer('isolir@snet', $odpDown, ['status' => 'isolated']);
        PppoeCustomer::factory()->for($this->router)->create(['pppoe_username' => 'belum@snet']); // belum di peta
        $this->mikrotik->active = ['online@snet'];

        $this->actingAs($this->tech)->get('/peta-jaringan')->assertOk()->assertSee('Peta Jaringan')->assertSee('/peta/', false);
        $d = $this->actingAs($this->tech)->getJson("/peta-jaringan/{$this->router->id}/data")->assertOk()->json();

        $status = collect($d['customers'])->pluck('status', 'username');
        $this->assertSame(['online@snet' => 'online', 'putus1@snet' => 'offline', 'putus2@snet' => 'offline', 'isolir@snet' => 'isolated'], $status->all());
        $odps = collect($d['odps'])->keyBy('name');
        $this->assertSame('up', $odps['ODP-A']['status']);
        $this->assertSame('down', $odps['ODP-B']['status'], 'semua pelanggan aktif di ODP putus = ODP/kabel putus (isolir tidak dihitung)');
        $this->assertSame(['online' => 1, 'offline' => 2, 'isolated' => 1, 'odp_down' => 1, 'unplaced' => 1], $d['summary']);
        $this->assertCount(1, $d['unplaced_list']);
    }

    public function test_technician_manages_odp_and_places_customer(): void
    {
        $id = $this->actingAs($this->tech)->postJson('/peta-jaringan/odp', ['router_id' => $this->router->id, 'name' => 'ODP-01', 'lat' => 0.8, 'lng' => 127.4])
            ->assertCreated()->json('id');
        $this->actingAs($this->tech)->putJson("/peta-jaringan/odp/{$id}", ['lat' => 0.81, 'lng' => 127.41])->assertOk();
        $this->assertEqualsWithDelta(0.81, Odp::find($id)->latitude, 0.0001);

        $c = PppoeCustomer::factory()->for($this->router)->create();
        $this->actingAs($this->tech)->putJson("/peta-jaringan/pelanggan/{$c->id}", ['lat' => 0.82, 'lng' => 127.42, 'odp_id' => $id])->assertOk();
        $this->assertSame($id, $c->fresh()->odp_id);
        // ODP dari cabang lain ditolak.
        $other = Odp::factory()->create();
        $this->actingAs($this->tech)->putJson("/peta-jaringan/pelanggan/{$c->id}", ['odp_id' => $other->id])->assertUnprocessable();

        $this->actingAs($this->tech)->deleteJson("/peta-jaringan/odp/{$id}")->assertOk();
        $this->assertNull($c->fresh()->odp_id, 'pelanggan dilepas dari ODP yang dihapus');
        $this->actingAs($this->tech)->putJson("/peta-jaringan/server/{$this->router->id}", ['lat' => 1, 'lng' => 2])->assertForbidden();
    }

    public function test_public_map_needs_secret_link_and_hides_private_data(): void
    {
        $this->customer('rahasia@snet', null, ['full_name' => 'Budi', 'phone' => '0812']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/peta-jaringan');
        $token = (string) app(SnetSettings::class)->get('pppoe', 'map_public_token');
        $this->assertSame(40, strlen($token));
        auth()->logout();

        $this->get('/peta/salah-token')->assertNotFound();
        $this->get("/peta/{$token}")->assertOk()->assertSee('Pilih cabang')->assertSee($this->router->name);
        $json = $this->getJson("/peta/{$token}/{$this->router->id}/data")->assertOk()->assertJsonPath('customers.0.name', 'Budi')->getContent();
        $this->assertStringNotContainsString('rahasia@snet', $json);
        $this->assertStringNotContainsString('0812', $json);

        $this->actingAs($admin)->post('/peta-jaringan/link-publik')->assertRedirect();
        auth()->logout();
        $this->get("/peta/{$token}")->assertNotFound();
    }

    public function test_customer_form_saves_odp_and_gps_location(): void
    {
        $odp = Odp::factory()->for($this->router)->create();
        $c = PppoeCustomer::factory()->for($this->router)->create();
        $this->actingAs($this->tech)->get("/customers/{$c->id}/edit")->assertOk()->assertSee('Lokasi saya')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');

        $this->actingAs($this->tech)->put("/customers/{$c->id}", [
            'router_id' => $this->router->id, 'full_name' => $c->full_name, 'pppoe_username' => $c->pppoe_username, 'profile' => '10M',
            'monthly_price' => 100000, 'due_day' => 5, 'status' => 'active', 'odp_id' => $odp->id, 'latitude' => '0.7912345', 'longitude' => '127.3712345',
        ])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame($odp->id, $c->odp_id);
        $this->assertEqualsWithDelta(0.7912345, $c->latitude, 0.0000001);
    }
}

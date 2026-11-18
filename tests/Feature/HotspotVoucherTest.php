<?php

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\Penagihan;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Hotspot\VoucherService;
use App\Services\Mikrotik\MikrotikConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class HotspotVoucherTest extends TestCase
{
    use RefreshDatabase;

    private HotspotProfile $profile;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MikrotikConnector::class, new FakeMikrotik);
        $this->operator = User::factory()->teknisi()->create();
        $this->profile = HotspotProfile::create(['name' => '1JAM', 'validity_value' => 1, 'validity_unit' => 'days', 'duration_value' => 1, 'duration_unit' => 'hours',
            'rate_up' => '2M', 'rate_down' => '5M', 'quota_mb' => 0, 'price' => 3000, 'include_in_sales' => true, 'is_active' => true]);
    }

    public function test_generate_batch_writes_radius_attributes(): void
    {
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 5, 'user_mode' => 'vc', 'char_length' => 6, 'char_type' => 'num', 'prefix' => 'SN'])
            ->assertRedirect();

        $this->assertSame(5, Voucher::count());
        $v = Voucher::first();
        $this->assertMatchesRegularExpression('/^SN[2-9]{6}$/', $v->username);
        $this->assertSame($v->username, $v->password);
        $this->assertDatabaseHas('radcheck', ['username' => $v->username, 'attribute' => 'Cleartext-Password', 'value' => $v->password]);
        $this->assertDatabaseHas('radreply', ['username' => $v->username, 'attribute' => 'Session-Timeout', 'value' => '3600']);
        $this->assertDatabaseHas('radreply', ['username' => $v->username, 'attribute' => 'Mikrotik-Rate-Limit', 'value' => '2M/5M']);

        $this->actingAs($this->operator)->get('/vouchers/print?batch_id='.$v->batch_id)->assertOk()->assertSee($v->username);
    }

    public function test_first_login_activates_logs_sale_and_expiry_removes_radius(): void
    {
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 1, 'user_mode' => 'vc', 'char_length' => 6, 'char_type' => 'mix']);
        $v = Voucher::first();
        DB::table('radacct')->insert(['acctsessionid' => 's1', 'acctuniqueid' => 'u1', 'username' => $v->username, 'nasipaddress' => '10.0.0.1',
            'acctstarttime' => now()->subMinutes(30), 'acctsessiontime' => 1800]);

        $this->artisan('snet:vouchers-expire')->assertSuccessful();
        $v->refresh();
        $this->assertSame('active', $v->status);
        $this->assertDatabaseHas('sales_log', ['voucher_id' => $v->id, 'price' => 3000]);
        $this->assertDatabaseHas('radreply', ['username' => $v->username, 'attribute' => 'Session-Timeout', 'value' => '1800']);

        // Waktu pakai habis -> kedaluwarsa & dicabut dari RADIUS.
        DB::table('radacct')->where('username', $v->username)->update(['acctsessiontime' => 3600]);
        $this->artisan('snet:vouchers-expire')->assertSuccessful();
        $this->assertSame('expired', $v->fresh()->status);
        $this->assertDatabaseMissing('radcheck', ['username' => $v->username]);
        $this->assertSame(1, DB::table('sales_log')->count(), 'penjualan tidak tercatat dua kali');
    }

    public function test_profile_change_updates_unused_vouchers(): void
    {
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 2, 'user_mode' => 'up', 'char_length' => 6, 'char_type' => 'mix']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put("/hotspot-profiles/{$this->profile->id}", $this->profile->only(['name', 'validity_value', 'validity_unit', 'duration_value', 'duration_unit', 'price']) + ['rate_up' => '1M', 'rate_down' => '3M', 'is_active' => 1, 'sync_existing_vouchers' => 1])
            ->assertRedirect();
        $this->assertSame(2, DB::table('radreply')->where('attribute', 'Mikrotik-Rate-Limit')->where('value', '1M/3M')->count());
        $this->actingAs($admin)->get('/hotspot-profiles')->assertOk()->assertSee('1JAM');
        $this->actingAs($admin)->get('/hotspot-profiles/create')->assertOk()->assertSee('Isi Otomatis dari Template');
        $this->actingAs($admin)->get("/hotspot-profiles/{$this->profile->id}/edit")->assertOk();
        $this->actingAs($this->operator)->get('/hotspot-profiles')->assertForbidden();
    }

    public function test_list_reactivate_and_bulk_delete(): void
    {
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 3, 'user_mode' => 'vc', 'char_length' => 6, 'char_type' => 'num']);
        [$a, $b] = Voucher::orderBy('id')->take(2)->get()->all();
        app(VoucherService::class)->retire($a, 'expired');

        $this->actingAs($this->operator)->get('/vouchers?status=expired')->assertOk()->assertSee($a->username)->assertDontSee($b->username);
        $this->actingAs($this->operator)->get('/vouchers/print?voucher_id='.$b->id)->assertOk()->assertSee($b->username);

        $this->actingAs($this->operator)->post('/vouchers/reactivate', ['voucher_ids' => (string) $a->id])->assertRedirect();
        $this->assertSame('unused', $a->fresh()->status);
        $this->assertDatabaseHas('radcheck', ['username' => $a->username]);

        $this->actingAs($this->operator)->post('/vouchers/bulk-delete', ['confirm_all' => 1])->assertSessionHasErrors('bulk');
        $this->actingAs($this->operator)->post('/vouchers/bulk-delete', ['batch_id' => $a->batch_id, 'confirm_all' => 1])->assertRedirect();
        $this->assertSame(0, Voucher::where('status', '!=', 'deleted')->count());
        $this->assertSame(0, DB::table('radcheck')->count());
    }

    public function test_active_users_json_feed_and_disconnect(): void
    {
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 1, 'user_mode' => 'vc', 'char_length' => 6, 'char_type' => 'num']);
        $v = Voucher::first();
        DB::table('radacct')->insert(['acctsessionid' => 's1', 'acctuniqueid' => 'u1', 'username' => $v->username, 'nasipaddress' => '10.0.0.1',
            'acctstarttime' => now()->subMinutes(30), 'acctsessiontime' => 1800, 'acctoutputoctets' => 1048576]);

        $res = $this->actingAs($this->operator)->getJson('/monitoring/active?format=json')->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('users.0.username', $v->username)->assertJsonPath('users.0.profile', '1JAM');
        $this->assertStringContainsString('30m', $res->json('users.0.sisa_waktu'));

        $this->actingAs($this->operator)->postJson('/monitoring/disconnect', ['radacctid' => $res->json('users.0.radacctid')])->assertJsonPath('success', true);
        $this->actingAs($this->operator)->getJson('/monitoring/active?format=json')->assertJsonPath('count', 0);
    }

    public function test_sales_report_and_rebuild(): void
    {
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 1, 'user_mode' => 'vc', 'char_length' => 6, 'char_type' => 'num']);
        $v = Voucher::first();
        DB::table('radacct')->insert(['acctsessionid' => 's1', 'acctuniqueid' => 'u1', 'username' => $v->username, 'nasipaddress' => '10.0.0.1', 'acctstarttime' => now(), 'acctsessiontime' => 60]);
        $this->artisan('snet:vouchers-expire')->assertSuccessful();

        $this->actingAs($this->operator)->get('/reports/sales')->assertOk()->assertSee($v->username)->assertSee('1JAM');
        DB::table('sales_log')->insert(['voucher_username' => 'DUPLIKAT', 'profile_name' => '1JAM', 'price' => 3000, 'sold_at' => now()]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($this->operator)->post('/reports/sales/rebuild')->assertForbidden();
        $this->actingAs($admin)->post('/reports/sales/rebuild')->assertRedirect();
        $this->assertSame(1, DB::table('sales_log')->count());
        $this->assertDatabaseHas('sales_log', ['voucher_id' => $v->id, 'price' => 3000]);
    }

    public function test_usage_report_clamps_duration_and_deletes_user_sessions(): void
    {
        DB::table('radacct')->insert([
            ['acctsessionid' => 'a', 'acctuniqueid' => 'a', 'username' => 'budi', 'nasipaddress' => '10.0.0.1', 'acctstarttime' => now()->startOfMonth()->addHour(),
                'acctstoptime' => now()->startOfMonth()->addHours(3), 'acctsessiontime' => 7200, 'acctoutputoctets' => 2 * 1073741824, 'acctinputoctets' => 1048576],
            ['acctsessionid' => 'b', 'acctuniqueid' => 'b', 'username' => 'ani', 'nasipaddress' => '10.0.0.1', 'acctstarttime' => now()->startOfMonth()->addHour(),
                'acctstoptime' => now()->startOfMonth()->addHours(2), 'acctsessiontime' => 3600, 'acctoutputoctets' => 1048576, 'acctinputoctets' => 0],
        ]);
        $this->actingAs($this->operator)->get('/reports/usage')->assertOk()->assertSee('budi')->assertSee('3 Jam')->assertSee('2 GB');
        $this->actingAs($this->operator)->get('/reports/usage/export')->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->delete('/reports/usage', ['username' => 'budi', 'nasipaddress' => '10.0.0.1'])->assertRedirect();
        $this->assertSame(['ani'], DB::table('radacct')->pluck('username')->all());
    }

    public function test_penagihan_flow_with_resellers_baseline_and_delete(): void
    {
        $router = Router::create(['name' => 'Cabang A', 'ip_address' => '10.0.0.1', 'api_user' => 'a', 'api_password' => 'b', 'api_port' => 8728, 'radius_secret' => 's', 'status' => 'active']);
        $this->profile->update(['reseller_percent' => 20, 'router_id' => $router->id]);
        $this->actingAs($this->operator)->post('/vouchers', ['profile_id' => $this->profile->id, 'qty' => 2, 'user_mode' => 'vc', 'char_length' => 6, 'char_type' => 'num']);
        Voucher::query()->update(['status' => 'active', 'used_at' => now()]);

        $this->actingAs($this->operator)->getJson("/reports/penagihan/resellers?router_id={$router->id}")->assertOk()
            ->assertJsonPath('0.vcr_baru', 2)->assertJsonPath('0.last_billed_date', 'Belum Pernah');
        $this->actingAs($this->operator)->post('/reports/penagihan', ['router_id' => $router->id, 'profile_id' => $this->profile->id, 'total_pendapatan' => 3000])->assertRedirect();
        $p = Penagihan::firstOrFail();
        $this->assertSame('tekor', $p->status_kecocokan);
        $this->actingAs($this->operator)->get('/reports/penagihan')->assertOk()->assertSee('Tekor 1 Voucher')->assertSee('Cabang A');
        $this->actingAs($this->operator)->get('/reports/penagihan/export')->assertOk();

        $this->actingAs(User::factory()->teknisi()->create())->delete("/reports/penagihan/{$p->id}")->assertForbidden();
        $this->actingAs($this->operator)->delete("/reports/penagihan/{$p->id}")->assertRedirect();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/reports/penagihan/baseline')->assertRedirect();
        $this->actingAs($admin)->getJson("/reports/penagihan/resellers?router_id={$router->id}")->assertJsonPath('0.voucher_aktual', 0);
    }
}

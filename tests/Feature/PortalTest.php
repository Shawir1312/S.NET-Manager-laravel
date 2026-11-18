<?php

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Models\Voucher;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Settings\SnetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private PppoeCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MikrotikConnector::class, new FakeMikrotik);
        $this->customer = PppoeCustomer::factory()->create(['portal_username' => 'budi', 'portal_password' => 'rahasia1', 'monthly_price' => 150000, 'due_day' => 5]);
        app(SnetSettings::class)->put('pppoe', ['active_payment_gateway' => 'midtrans', 'midtrans_server_key' => 'SB-server', 'midtrans_client_key' => 'SB-client']);
    }

    private function login(): void
    {
        $this->post('/portal/login', ['username' => 'BUDI', 'password' => 'rahasia1'])->assertRedirect('/portal');
    }

    public function test_login_is_rate_limited_per_username(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/portal/login', ['username' => 'budi', 'password' => 'salah'])->assertSessionHasErrors('username');
        }
        $this->post('/portal/login', ['username' => 'budi', 'password' => 'rahasia1'])->assertSessionHasErrors(['username' => 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.']);
        $this->assertNull(session('portal_customer_id'));
    }

    public function test_radius_password_fallback_migrates_to_hashed_portal_password(): void
    {
        $c = PppoeCustomer::factory()->create(['portal_username' => 'lama', 'portal_password' => null]);
        DB::table('radcheck')->insert(['username' => $c->pppoe_username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => '55667788']);

        $this->post('/portal/login', ['username' => 'lama', 'password' => '55667788'])->assertRedirect('/portal');
        $this->assertTrue(password_verify('55667788', $c->fresh()->portal_password));
    }

    public function test_change_password_works(): void
    {
        $this->login();
        $this->post('/portal/password', ['current_password' => 'salah', 'password' => 'baru1234', 'password_confirmation' => 'baru1234'])->assertSessionHasErrors('current_password');
        $this->post('/portal/password', ['current_password' => 'rahasia1', 'password' => 'baru1234', 'password_confirmation' => 'baru1234'])->assertSessionHas('status');
        $this->assertTrue(password_verify('baru1234', $this->customer->fresh()->portal_password));
    }

    public function test_pay_amount_comes_from_database_not_browser(): void
    {
        $this->travelTo('2026-11-20');
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok-1'])]);
        $this->login();

        $this->get('/portal')->assertOk()->assertSee('Rp 150.000');
        $this->postJson('/portal/pay', ['period' => '2026-11', 'gateway' => 'midtrans', 'amount' => 1000])->assertOk()->assertJsonPath('token', 'tok-1');

        Http::assertSent(fn ($r) => $r['transaction_details']['gross_amount'] === 150000);
        $this->assertSame(150000, PppoePayment::firstOrFail()->amount);
        $this->postJson('/portal/pay', ['period' => '2025-01', 'gateway' => 'midtrans'])->assertStatus(422);
    }

    public function test_midtrans_webhook_requires_signature_and_matching_amount(): void
    {
        $this->travelTo('2026-11-20');
        $this->customer->update(['status' => 'isolated']);
        $this->customer->payments()->create(['amount' => 150000, 'payment_method' => 'cash', 'midtrans_status' => 'paid', 'period_month' => 10, 'period_year' => 2026, 'paid_at' => now()]);
        $p = $this->customer->payments()->create(['amount' => 150000, 'payment_method' => 'midtrans', 'midtrans_order_id' => 'INV-1', 'midtrans_status' => 'pending', 'period_month' => 11, 'period_year' => 2026]);
        $body = fn ($amount, $sig = null) => ['order_id' => 'INV-1', 'status_code' => '200', 'gross_amount' => $amount, 'transaction_status' => 'settlement', 'fraud_status' => 'accept',
            'signature_key' => $sig ?? hash('sha512', 'INV-1200'.$amount.'SB-server')];

        $this->postJson('/webhooks/payment', $body('150000.00', 'palsu'))->assertForbidden();
        $this->postJson('/webhooks/payment', $body('1000.00'))->assertOk();
        $this->assertSame('pending', $p->fresh()->midtrans_status, 'nominal beda -> tidak lunas');

        $this->postJson('/webhooks/payment', $body('150000.00'))->assertOk();
        $this->assertSame('paid', $p->fresh()->midtrans_status);
        $this->assertSame('active', $this->customer->fresh()->status, 'isolir dibuka otomatis');
    }

    public function test_isolir_page_identifies_customer_by_session_ip_only(): void
    {
        $this->get('/isolir?user='.$this->customer->pppoe_username)->assertOk()->assertDontSee(explode(' ', $this->customer->full_name)[0]);

        DB::table('radacct')->insert(['acctsessionid' => 'x', 'acctuniqueid' => 'y', 'username' => $this->customer->pppoe_username, 'nasipaddress' => '10.0.0.1', 'framedipaddress' => '10.9.9.9', 'acctstarttime' => now()]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->get('/isolir')->assertOk()->assertSee(explode(' ', $this->customer->full_name)[0]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])->withHeader('X-Forwarded-For', '10.9.9.9')->get('/isolir')->assertDontSee(explode(' ', $this->customer->full_name)[0]);
    }

    public function test_voucher_extend_logs_sale_and_retires_old(): void
    {
        $profile = HotspotProfile::create(['name' => '1H', 'validity_value' => 1, 'validity_unit' => 'days', 'duration_value' => 0, 'duration_unit' => 'hours', 'price' => 5000, 'include_in_sales' => true, 'is_active' => true]);
        $old = Voucher::create(['username' => 'OLD1', 'password' => 'OLD1', 'profile_id' => $profile->id, 'status' => 'expired']);
        Voucher::create(['username' => 'NEW1', 'password' => 'NEW1', 'profile_id' => $profile->id, 'status' => 'unused']);

        $this->post('/voucher/extend', ['old_code' => 'OLD1', 'new_code' => 'NEW1'])->assertOk()->assertSee('berhasil diperpanjang');
        $this->assertSame('active', Voucher::where('username', 'NEW1')->value('status'));
        $this->assertDatabaseHas('sales_log', ['voucher_username' => 'NEW1', 'price' => 5000]);
        $this->post('/voucher/extend', ['old_code' => 'OLD1', 'new_code' => 'NEW1'])->assertSee('sudah terpakai');
        $this->get('/voucher?code=NEW1')->assertOk()->assertHeaderMissing('X-Frame-Options');
    }
}

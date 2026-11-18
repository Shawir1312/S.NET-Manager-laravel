<?php

namespace Tests\Feature;

use App\Models\PppoeCustomer;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Pppoe\BillingService;
use App\Services\Settings\SnetSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeMikrotik;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotik $mikrotik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mikrotik = new FakeMikrotik;
        $this->app->instance(MikrotikConnector::class, $this->mikrotik);
        Http::fake(['127.0.0.1:3000/*' => Http::response(['success' => true])]);
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    public function test_overdue_after_grace_period_only(): void
    {
        $c = PppoeCustomer::factory()->create(['due_day' => 10]);
        // Bulan lalu sudah lunas.
        $c->payments()->create(['amount' => 150000, 'payment_method' => 'cash', 'midtrans_status' => 'paid', 'period_month' => 10, 'period_year' => 2026, 'paid_at' => now()]);

        $this->assertNull($this->billing()->overdueReason($c, CarbonImmutable::parse('2026-11-12')), 'masih masa tenggang 3 hari');
        $this->assertStringContainsString('11/2026', $this->billing()->overdueReason($c, CarbonImmutable::parse('2026-11-13')));

        PppoeCustomer::factory()->create(['is_free' => true, 'monthly_price' => 0]);
        $this->assertNull($this->billing()->overdueReason(PppoeCustomer::where('is_free', true)->first(), CarbonImmutable::parse('2026-12-31')));
    }

    public function test_auto_isolir_then_payment_reactivates(): void
    {
        $this->travelTo('2026-11-20');
        $c = PppoeCustomer::factory()->create(['due_day' => 5]);
        $this->mikrotik->secrets[$c->pppoe_username] = ['name' => $c->pppoe_username, 'profile' => '10Mbps'];

        $this->artisan('snet:auto-isolir')->expectsOutputToContain('Diisolir: 1')->assertSuccessful();
        $this->assertSame('isolated', $c->fresh()->status);
        $this->assertSame('isolir', $this->mikrotik->secrets[$c->pppoe_username]['profile']);
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'Terisolir'));

        // Bayar bulan lalu & bulan ini lewat kasir -> otomatis aktif lagi.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/payments', ['customer_id' => $c->id, 'period' => '2026-10', 'amount' => 150000, 'payment_method' => 'cash'])->assertRedirect();
        $this->assertSame('isolated', $c->fresh()->status, 'bulan ini belum lunas');
        $this->actingAs($admin)->post('/payments', ['customer_id' => $c->id, 'period' => '2026-11', 'amount' => 150000, 'payment_method' => 'cash'])->assertRedirect();
        $this->assertSame('active', $c->fresh()->status);
        $this->assertSame('10Mbps', $this->mikrotik->secrets[$c->pppoe_username]['profile']);

        $this->actingAs($admin)->post('/payments', ['customer_id' => $c->id, 'period' => '2026-11', 'amount' => 150000, 'payment_method' => 'cash'])->assertSessionHasErrors('period');
    }

    public function test_reminders_sent_on_h3_h1_h0_only_when_unpaid(): void
    {
        PppoeCustomer::factory()->create(['due_day' => 23, 'full_name' => 'Tiga']);
        PppoeCustomer::factory()->create(['due_day' => 21, 'full_name' => 'Satu']);
        PppoeCustomer::factory()->create(['due_day' => 25, 'full_name' => 'Lima']);
        $paid = PppoeCustomer::factory()->create(['due_day' => 20, 'full_name' => 'Lunas']);
        $paid->payments()->create(['amount' => 1, 'payment_method' => 'cash', 'midtrans_status' => 'paid', 'period_month' => 11, 'period_year' => 2026, 'paid_at' => now()]);

        $r = $this->billing()->sendReminders(CarbonImmutable::parse('2026-11-20'));

        $this->assertSame(['reminder_h3' => 1, 'reminder_h1' => 1, 'reminder_h0' => 0], $r);
    }

    public function test_receipt_requires_signature(): void
    {
        $c = PppoeCustomer::factory()->create();
        $p = $c->payments()->create(['amount' => 150000, 'payment_method' => 'cash', 'midtrans_status' => 'paid', 'midtrans_order_id' => 'MANUAL-X', 'period_month' => 11, 'period_year' => 2026, 'paid_at' => now()]);

        $this->get("/receipt/{$p->id}")->assertNotFound();
        $this->get("/receipt/{$p->id}?signature=salah")->assertNotFound();
        $this->get("/receipt/{$p->id}?signature=".BillingService::receiptSignature($p))->assertOk()->assertSee('MANUAL-X')->assertSee('LUNAS');
    }

    public function test_settings_encrypt_gateway_secrets_and_keep_blank(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/pppoe-settings', ['isolir_grace_days' => 2, 'isolir_profile' => 'isolir', 'midtrans_server_key' => 'SB-Mid-server-xyz'])->assertRedirect();
        $raw = \DB::table('pppoe_settings')->where('setting_key', 'midtrans_server_key')->value('setting_value');
        $this->assertNotSame('SB-Mid-server-xyz', $raw);

        $this->actingAs($admin)->put('/pppoe-settings', ['isolir_grace_days' => 2, 'isolir_profile' => 'isolir', 'midtrans_server_key' => ''])->assertRedirect();
        $this->assertSame('SB-Mid-server-xyz', app(SnetSettings::class)->secret('pppoe', 'midtrans_server_key'));
        $this->actingAs($admin)->get('/pppoe-settings')->assertOk()->assertDontSee('SB-Mid-server-xyz');
    }
}

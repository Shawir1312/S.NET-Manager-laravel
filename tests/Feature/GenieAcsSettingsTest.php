<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GenieAcsSetting;
use App\Models\User;
use Database\Seeders\GenieAcsSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenieAcsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenieAcsSettingSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'nbi_url' => 'http://10.0.0.5:7557',
            'cwmp_url' => 'http://10.0.0.5:7547',
            'username' => 'nbiuser',
            'password' => 'SuperRahasia!42',
            'connect_timeout' => 5,
            'request_timeout' => 30,
            'connection_request_timeout' => 10,
            'verify_tls' => 1,
        ], $override);
    }

    public function test_credentials_are_encrypted_at_rest_and_never_rendered(): void
    {
        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload())
            ->assertRedirect('/settings/genieacs');

        $raw = DB::table('genieacs_settings')->first();
        $this->assertNotSame('SuperRahasia!42', $raw->password);
        $this->assertStringNotContainsString('SuperRahasia', $raw->password);
        $this->assertSame('SuperRahasia!42', GenieAcsSetting::active()->password);

        $this->actingAs($this->admin)->get('/settings/genieacs')
            ->assertOk()
            ->assertDontSee('SuperRahasia!42')
            ->assertDontSee('nbiuser')
            ->assertSee('tersimpan terenkripsi');

        $this->assertArrayNotHasKey('password', GenieAcsSetting::active()->toArray());
    }

    public function test_blank_password_keeps_existing_and_clear_removes(): void
    {
        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload());
        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload(['username' => '', 'password' => '']));
        $this->assertSame('SuperRahasia!42', GenieAcsSetting::active()->password);

        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload(['username' => '', 'password' => '', 'clear_credentials' => 1]));
        $this->assertNull(GenieAcsSetting::active()->password);
        $this->assertNull(GenieAcsSetting::active()->username);
    }

    public function test_update_is_audited_without_secrets(): void
    {
        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload());

        $log = AuditLog::where('action', 'genieacs.settings_updated')->firstOrFail();
        $this->assertStringNotContainsString('SuperRahasia', json_encode($log->changes));
        $this->assertTrue($log->changes['credentials_changed']);
    }

    public function test_validation_rejects_bad_values(): void
    {
        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload(['nbi_url' => 'ftp://x', 'connect_timeout' => 0]))
            ->assertSessionHasErrors(['nbi_url', 'connect_timeout']);
    }

    public function test_connection_success_uses_basic_auth_and_records_status(): void
    {
        $this->actingAs($this->admin)->put('/settings/genieacs', $this->payload());
        Http::fake(['10.0.0.5:7557/devices/*' => Http::response([['_id' => 'X']], 200)]);

        $this->actingAs($this->admin)->postJson('/api/genieacs/test-connection')
            ->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/devices/?projection=_id&limit=1') && $r->hasHeader('Authorization'));
        $this->assertSame('ok', GenieAcsSetting::active()->last_test_status);
    }

    public function test_connection_unreachable_is_reported_clearly(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->actingAs($this->admin)->postJson('/api/genieacs/test-connection')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['message' => 'GenieACS tidak dapat diakses. Periksa URL NBI, jaringan, dan firewall.']);

        $this->assertSame('failed', GenieAcsSetting::active()->last_test_status);
    }

    public function test_connection_auth_failure(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $this->actingAs($this->admin)->postJson('/api/genieacs/test-connection')
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['message' => 'Autentikasi ke GenieACS NBI gagal. Periksa username/password API.']);
    }

    public function test_non_genieacs_response_is_not_reported_as_success(): void
    {
        Http::fake(['*' => Http::response('<html>nginx</html>', 200)]);

        $this->actingAs($this->admin)->postJson('/api/genieacs/test-connection')->assertJson(['ok' => false]);
    }
}

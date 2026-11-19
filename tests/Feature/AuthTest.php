<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('S.NET MANAGER');
    }

    public function test_user_can_login_and_login_is_audited(): void
    {
        $user = User::factory()->admin()->create(['password' => 'rahasia-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_legacy_admin_can_login_with_username(): void
    {
        $user = User::factory()->admin()->create(['username' => 'operator1', 'password' => 'rahasia-123']);

        $this->post('/login', ['email' => 'operator1', 'password' => 'rahasia-123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected_and_not_logged_in_plaintext(): void
    {
        $user = User::factory()->admin()->create(['password' => 'rahasia-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'salah-sekali'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $this->assertStringNotContainsString('salah-sekali', json_encode($log->changes));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'rahasia-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_user_is_kicked_out_of_existing_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $user = User::factory()->admin()->create(['password' => 'rahasia-123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-123'])
            ->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Terlalu banyak percobaan login', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}

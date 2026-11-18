<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ]);
        }

        // Admin S.NET lama login memakai username; akun baru boleh email.
        $field = filter_var($credentials['email'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $attempt = [$field => $credentials['email'], 'password' => $credentials['password']];

        if (! Auth::attempt($attempt, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            $audit->log('auth.login_failed', null, ['email' => $credentials['email']]);

            throw ValidationException::withMessages(['email' => 'Username/email atau password salah.']);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            $audit->log('auth.login_blocked_inactive', $user, [], $user->id);

            throw ValidationException::withMessages(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $audit->log('auth.login', $user, [], $user->id);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            $audit->log('auth.logout', $user, [], $user->id);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

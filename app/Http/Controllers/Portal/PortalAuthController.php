<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\PppoeCustomer;
use App\Services\Radius\RadiusSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PortalAuthController extends Controller
{
    /** Batas percobaan per username: 5 kali gagal -> kunci 15 menit (dulu tanpa batas). */
    private const MAX_ATTEMPTS = 5;

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('portal_customer_id')) {
            return redirect()->route('portal.home');
        }
        $announcements = Announcement::current()->where('show_on_login', true)->latest('id')->get();

        return view('portal.login', ['announcements' => $announcements, 'maintenance' => $announcements->firstWhere('maintenance_mode', true)]);
    }

    public function login(Request $request, RadiusSync $radius): RedirectResponse
    {
        $d = $request->validate(['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:100']]);
        if (Announcement::current()->where('maintenance_mode', true)->exists()) {
            throw ValidationException::withMessages(['username' => 'Portal sedang maintenance. Coba beberapa saat lagi.']);
        }

        $key = 'portal-login:'.Str::lower($d['username']);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan gagal. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.']);
        }

        $matches = PppoeCustomer::whereIn('status', ['active', 'isolated'])
            ->where(fn ($q) => $q->whereRaw('LOWER(portal_username) = ?', [Str::lower($d['username'])])->orWhereRaw('LOWER(pppoe_username) = ?', [Str::lower($d['username'])]))
            ->limit(2)->get();
        $customer = $matches->count() === 1 ? $matches->first() : null;

        $valid = $customer && ($customer->portal_password
            ? Hash::check($d['password'], $customer->portal_password)
            : hash_equals((string) $radius->passwordOf($customer->pppoe_username), $d['password']));

        if (! $valid) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['username' => 'Username atau password salah.']);
        }

        RateLimiter::clear($key);
        if (! $customer->portal_password) {
            $customer->forceFill(['portal_password' => $d['password']])->save(); // migrasi: password RADIUS -> hash portal
        }
        $request->session()->regenerate();
        $request->session()->put('portal_customer_id', $customer->id);

        return redirect()->route('portal.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('portal_customer_id');
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}

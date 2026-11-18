<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('manage-users');

        return view('users.index', ['users' => User::orderBy('name')->paginate(25)]);
    }

    public function create(): View
    {
        Gate::authorize('manage-users');

        return view('users.form', ['user' => new User(['role' => Role::Teknisi, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');
        $d = $this->validated($request);
        $user = User::create($d + ['is_active' => $request->boolean('is_active', true)]);
        $this->audit->log('user.created', $user, ['email' => $user->email, 'role' => $user->role->value]);

        return redirect()->route('users.index')->with('status', "User {$user->name} dibuat.");
    }

    public function edit(User $user): View
    {
        Gate::authorize('manage-users');

        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-users');
        $d = $this->validated($request, $user);
        $active = $request->boolean('is_active');

        if ($user->is($request->user()) && ($d['role'] !== Role::Admin->value || ! $active)) {
            return back()->withErrors(['role' => 'Anda tidak bisa menurunkan role atau menonaktifkan akun sendiri.']);
        }
        if ($user->isAdmin() && ($d['role'] !== Role::Admin->value || ! $active) && $this->activeAdmins() <= 1) {
            return back()->withErrors(['role' => 'Harus ada minimal satu admin aktif.']);
        }

        if (blank($d['password'] ?? null)) {
            unset($d['password']);
        }
        $before = ['role' => $user->role->value, 'is_active' => $user->is_active];
        $user->update($d + ['is_active' => $active]);
        $this->audit->log('user.updated', $user, ['before' => $before, 'after' => ['role' => $user->role->value, 'is_active' => $user->is_active], 'password_changed' => isset($d['password'])]);

        return redirect()->route('users.index')->with('status', "User {$user->name} diperbarui.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-users');
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'Anda tidak bisa menghapus akun sendiri.']);
        }
        if ($user->isAdmin() && $this->activeAdmins() <= 1) {
            return back()->withErrors(['user' => 'Admin terakhir tidak bisa dihapus.']);
        }
        if ($user->configurationJobs()->exists()) {
            // Jejak audit dipertahankan: user dengan riwayat konfigurasi hanya dinonaktifkan.
            $user->update(['is_active' => false]);
            $this->audit->log('user.deactivated', $user, ['reason' => 'memiliki riwayat konfigurasi']);

            return back()->with('status', "{$user->name} memiliki riwayat konfigurasi, jadi akun dinonaktifkan (tidak dihapus).");
        }
        $user->delete();
        $this->audit->log('user.deleted', null, ['email' => $user->email]);

        return back()->with('status', "User {$user->name} dihapus.");
    }

    private function activeAdmins(): int
    {
        return User::where('role', Role::Admin)->where('is_active', true)->count();
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/', Rule::unique('users')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:191', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
    }
}

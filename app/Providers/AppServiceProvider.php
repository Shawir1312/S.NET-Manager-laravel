<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Services\GenieACS\GenieACSService;
use App\Services\Settings\SnetSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // GenieACSService selalu memakai pengaturan aktif dari database.
        $this->app->bind(GenieACSService::class, fn () => GenieACSService::fromActiveSetting());
        $this->app->scoped(SnetSettings::class);
    }

    public function boot(): void
    {
        // ---- Hak akses (RBAC) ----
        Gate::define('configure-ont', fn (User $user) => $user->is_active && $user->role->canConfigure());
        Gate::define('view-devices', fn (User $user) => $user->is_active);
        // Menu operator S.NET: pelanggan, voucher, monitoring, laporan.
        Gate::define('operate', fn (User $user) => $user->is_active && $user->role->canConfigure());

        foreach (['manage-settings', 'manage-users', 'manage-models', 'manage-templates', 'view-audit'] as $ability) {
            Gate::define($ability, fn (User $user) => $user->is_active && $user->role === Role::Admin);
        }

        // ---- Rate limiting ----
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Aksi yang menyentuh GenieACS dibatasi lebih ketat.
        RateLimiter::for('genieacs', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}

<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ConfigurationJob;
use App\Models\User;

/** Admin: semua job. Teknisi: job miliknya. Viewer: tidak melihat riwayat konfigurasi. */
class ConfigurationJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role->canConfigure();
    }

    public function view(User $user, ConfigurationJob $job): bool
    {
        return $user->is_active && ($user->role === Role::Admin || ($user->role === Role::Teknisi && $job->user_id === $user->id));
    }

    public function rollback(User $user, ConfigurationJob $job): bool
    {
        return $this->view($user, $job) && $user->role->canConfigure();
    }
}

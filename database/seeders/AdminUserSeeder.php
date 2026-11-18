<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Membuat admin awal dari .env (ADMIN_EMAIL, ADMIN_PASSWORD).
     * Jika ADMIN_PASSWORD kosong, password acak dibuat dan ditampilkan SEKALI di konsol.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@ont.local');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} sudah ada, dilewati.");

            return;
        }

        $password = env('ADMIN_PASSWORD');
        $generated = blank($password);
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::create([
            'name' => env('ADMIN_NAME', 'Administrator'),
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        $this->command?->info("Admin dibuat: {$email}");
        if ($generated) {
            $this->command?->warn("Password admin (catat sekarang, tidak ditampilkan lagi): {$password}");
        }
    }
}

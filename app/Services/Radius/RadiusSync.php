<?php

namespace App\Services\Radius;

use App\Models\PppoeCustomer;
use App\Services\Settings\SnetSettings;
use Illuminate\Support\Facades\DB;

/**
 * Menulis atribut user ke tabel FreeRADIUS (radcheck/radreply/radusergroup).
 * Logika sama dengan save_pppoe_customer.php lama, dalam satu tempat.
 */
class RadiusSync
{
    public function __construct(private readonly SnetSettings $settings) {}

    /**
     * Sinkronkan pelanggan PPPoE. $password null = pertahankan password RADIUS lama.
     */
    public function syncCustomer(PppoeCustomer $customer, ?string $password = null, ?string $oldUsername = null): void
    {
        $username = $customer->pppoe_username;
        $password ??= $this->passwordOf($oldUsername ?: $username);

        DB::transaction(function () use ($customer, $username, $password, $oldUsername) {
            if ($oldUsername && $oldUsername !== $username) {
                $this->forget($oldUsername);
            }
            $this->forget($username);

            if ($customer->status === 'suspended') {
                DB::table('radcheck')->insert(['username' => $username, 'attribute' => 'Auth-Type', 'op' => ':=', 'value' => 'Reject']);

                return;
            }

            $group = $this->groupFor($customer);
            if ($password !== null && $password !== '') {
                DB::table('radcheck')->insert(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password]);
            }
            DB::table('radcheck')->insert(['username' => $username, 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '1']);
            DB::table('radreply')->insert([
                ['username' => $username, 'attribute' => 'Framed-Protocol', 'op' => ':=', 'value' => 'PPP'],
                ['username' => $username, 'attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => $group],
            ]);
            DB::table('radusergroup')->insert(['username' => $username, 'groupname' => $group, 'priority' => 1]);
        });
    }

    /** Hapus semua atribut RADIUS milik user. */
    public function forget(string $username): void
    {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            DB::table($table)->where('username', $username)->delete();
        }
    }

    public function passwordOf(string $username): ?string
    {
        return DB::table('radcheck')->where('username', $username)->where('attribute', 'Cleartext-Password')->value('value');
    }

    /** Profil MikroTik yang berlaku: pelanggan terisolir memakai profil isolir. */
    public function groupFor(PppoeCustomer $customer): string
    {
        return $customer->status === 'isolated'
            ? (string) $this->settings->get('pppoe', 'isolir_profile', 'isolir')
            : ($customer->profile ?: 'default');
    }
}

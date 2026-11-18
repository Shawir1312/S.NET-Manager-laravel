<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Services\Settings\SnetSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Impor data dari database S.NET lama (koneksi `legacy`) ke database baru.
 * Aman diulang: baris di-upsert dengan ID yang sama; tabel RADIUS disalin ulang penuh.
 */
class SnetImport extends Command
{
    protected $signature = 'snet:import {--source=legacy : Nama koneksi DB lama} {--skip-acct : Lewati radacct (riwayat sesi, bisa sangat besar)}';

    protected $description = 'Impor data S.NET Manager lama (PHP native) ke aplikasi Laravel';

    private Connection $src;

    /** @var array<int, int> id admin lama -> id user baru */
    private array $users = [];

    /** @var array<int, int> id genie_config lama -> id genieacs_settings baru */
    private array $servers = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function handle(): int
    {
        $this->src = DB::connection($this->option('source'));
        Schema::disableForeignKeyConstraints();

        try {
            $this->importUsers();
            $this->importGenieServers();

            $this->copy('routers', fn ($r) => ['genie_acs_setting_id' => $this->servers[$r['genie_server_id'] ?? 0] ?? null,
                'api_password' => $this->enc($r['api_password'] ?? null), 'updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('profiles', fn ($r) => ['updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('vouchers', fn ($r) => ['generated_by' => $this->user($r['generated_by'] ?? null), 'updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('sales_log', fn ($r) => ['sold_by' => $this->user($r['sold_by'] ?? null)] + $r);
            $this->copy('penagihan', fn ($r) => ['ditagih_oleh' => $this->user($r['ditagih_oleh'] ?? null) ?? 0, 'updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('pppoe_customers', fn ($r) => $this->customer($r));
            $this->copy('pppoe_payments', fn ($r) => ['created_by' => $this->user($r['created_by'] ?? null), 'created_at' => $r['paid_at'] ?? now(), 'updated_at' => $r['paid_at'] ?? now()] + $r);
            $this->copySettings();
            $this->copy('pppoe_bandwidth_snapshots');
            $this->copy('wa_config', fn ($r) => ['api_token' => $this->enc($r['api_token'] ?? null), 'created_at' => $r['updated_at'] ?? now()] + $r);
            $this->copy('wa_templates');
            $this->copy('wa_logs');
            $this->copy('wa_inbox');
            $this->copy('wg_routers', fn ($r) => ['private_key' => $this->enc($r['private_key'] ?? null), 'updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('wg_port_forwards', fn ($r) => ['updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('wg_logs');
            $this->copy('announcements', fn ($r) => ['created_by' => $this->user($r['created_by'] ?? null)] + $r);
            $this->copy('redundant_nodes', fn ($r) => ['updated_at' => $r['created_at'] ?? now()] + $r);
            $this->copy('redundant_logs');

            foreach (['radcheck', 'radreply', 'radgroupcheck', 'radgroupreply', 'radusergroup', 'radpostauth', 'nas', ...($this->option('skip-acct') ? [] : ['radacct'])] as $t) {
                $this->copy($t, null, replace: true);
            }
            $this->importAudit();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Impor selesai. Cek jumlah data di atas lalu login dengan username admin lama.');

        return self::SUCCESS;
    }

    private function exists(string $table): bool
    {
        return $this->src->getSchemaBuilder()->hasTable($table);
    }

    private function enc(?string $v): ?string
    {
        return $v === null || $v === '' ? null : Crypt::encryptString($v);
    }

    private function user(mixed $legacyId): ?int
    {
        return $this->users[(int) $legacyId] ?? null;
    }

    /** Salin tabel apa adanya (kolom yang sama), dengan transformasi per baris opsional. */
    private function copy(string $table, ?callable $map = null, bool $replace = false): void
    {
        if (! $this->exists($table)) {
            $this->line("  - {$table}: tidak ada di DB lama, dilewati");

            return;
        }
        $cols = $this->columns[$table] ??= Schema::getColumnListing($table);
        $key = match ($table) {
            'radacct' => 'radacctid', 'wg_settings', 'redundant_settings' => 'key', default => in_array('id', $cols, true) ? 'id' : null
        };
        if ($replace) {
            DB::table($table)->delete();
        }

        $n = 0;
        $query = $this->src->table($table);
        $each = function ($rows) use ($table, $cols, $key, $map, $replace, &$n) {
            $batch = [];
            foreach ($rows as $row) {
                $row = $map ? $map((array) $row) : (array) $row;
                if ($row !== null) {
                    $batch[] = array_intersect_key($row, array_flip($cols));
                }
            }
            if ($batch !== []) {
                $replace || ! $key ? DB::table($table)->insert($batch) : DB::table($table)->upsert($batch, [$key]);
                $n += count($batch);
            }
        };
        $key && $this->src->getSchemaBuilder()->hasColumn($table, $key)
            ? $query->orderBy($key)->chunk(500, $each)
            : $each($query->get());

        $this->line("  ✓ {$table}: {$n}");
    }

    private function importUsers(): void
    {
        if (! $this->exists('admins')) {
            return;
        }
        foreach ($this->src->table('admins')->get() as $a) {
            $attrs = [
                'name' => $a->full_name ?: $a->username,
                // Hash bcrypt PHP (password_hash) langsung kompatibel dengan Laravel.
                'password' => str_starts_with((string) $a->password, '$2y$') ? $a->password : Hash::make((string) $a->password),
                'role' => $a->role === 'superadmin' ? Role::Admin->value : Role::Teknisi->value,
                'is_active' => (bool) $a->is_active,
                'router_access' => $a->router_access ?? null,
                'updated_at' => now(),
            ];
            $existing = DB::table('users')->where('username', $a->username)->value('id');
            if ($existing) {
                DB::table('users')->where('id', $existing)->update($attrs);
            } else {
                $existing = DB::table('users')->insertGetId($attrs + ['username' => $a->username, 'email' => Str::slug($a->username).'@snet.local', 'created_at' => $a->created_at ?? now()]);
            }
            $this->users[(int) $a->id] = (int) $existing;
        }
        $this->line('  ✓ admins -> users: '.count($this->users).' (email sementara: <username>@snet.local)');
    }

    private function importGenieServers(): void
    {
        if (! $this->exists('genie_config')) {
            return;
        }
        foreach ($this->src->table('genie_config')->get() as $g) {
            $url = rtrim((string) $g->url, '/');
            $attrs = ['name' => $g->name ?: 'GenieACS', 'username' => $this->enc($g->username), 'password' => $this->enc($g->password), 'is_active' => (bool) $g->is_active, 'updated_at' => now()];
            $id = DB::table('genieacs_settings')->where('nbi_url', $url)->value('id');
            $id ? DB::table('genieacs_settings')->where('id', $id)->update($attrs)
                : $id = DB::table('genieacs_settings')->insertGetId($attrs + ['nbi_url' => $url, 'connect_timeout' => 5, 'request_timeout' => 30, 'connection_request_timeout' => 10, 'verify_tls' => false, 'created_at' => now()]);
            $this->servers[(int) $g->id] = (int) $id;
        }
        $this->line('  ✓ genie_config -> genieacs_settings: '.count($this->servers));
    }

    /** @param  array<string, mixed>  $r */
    private function customer(array $r): array
    {
        $hash = (string) ($r['portal_password'] ?? '');
        $plain = (string) ($r['portal_password_plain'] ?? '');

        return [
            'portal_username' => ($r['portal_username'] ?? '') ?: null,
            'portal_password' => str_starts_with($hash, '$2y$') ? $hash : (($plain ?: $hash) !== '' ? Hash::make($plain ?: $hash) : null),
            'ont_wifi_pass' => $this->enc($r['ont_wifi_pass'] ?? null),
            'ont_sn' => strtoupper(trim((string) ($r['ont_sn'] ?? ''))) === '0' ? '' : strtoupper(trim((string) ($r['ont_sn'] ?? ''))),
            'created_by' => $this->user($r['created_by'] ?? null),
        ] + $r;
    }

    private function copySettings(): void
    {
        if ($this->exists('pppoe_settings')) {
            $values = $this->src->table('pppoe_settings')->pluck('setting_value', 'setting_key')->all();
            app(SnetSettings::class)->put('pppoe', $values); // kunci rahasia otomatis dienkripsi
            $this->line('  ✓ pppoe_settings: '.count($values));
        }
        foreach (['wg_settings' => 'wireguard', 'redundant_settings' => 'redundant'] as $table => $group) {
            if ($this->exists($table)) {
                // Private key server WireGuard cukup ada di /etc/wireguard, tidak disalin ke DB baru.
                app(SnetSettings::class)->put($group, collect($this->src->table($table)->pluck('value', 'key'))->except('wg_server_privkey')->all());
                $this->line("  ✓ {$table}");
            }
        }
    }

    private function importAudit(): void
    {
        if (! $this->exists('audit_log') || DB::table('audit_logs')->where('action', 'like', 'legacy.%')->exists()) {
            return;
        }
        $this->src->table('audit_log')->orderBy('id')->chunk(1000, function ($rows) {
            DB::table('audit_logs')->insert($rows->map(fn ($r) => [
                'user_id' => $this->user($r->admin_id), 'action' => 'legacy.'.Str::limit((string) $r->action, 90, ''),
                'ip_address' => Str::limit((string) $r->ip_address, 45, ''), 'created_at' => $r->created_at,
                'changes' => json_encode(['admin' => $r->admin_name, 'target' => $r->target, 'detail' => $r->detail]),
            ])->all());
        });
        $this->line('  ✓ audit_log');
    }
}

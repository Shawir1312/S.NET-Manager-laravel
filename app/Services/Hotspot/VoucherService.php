<?php

namespace App\Services\Hotspot;

use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\SalesLog;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Audit\AuditLogger;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Voucher hotspot FreeRADIUS: generate batch, tandai aktif saat pertama login (catat penjualan),
 * hitung sisa waktu, kedaluwarsa. Port dari generate_voucher.php & run_auto_expire_vouchers().
 */
class VoucherService
{
    public const MAX_BATCH = 500;

    /** Karakter mirip (0/O, 1/l/I) sengaja dibuang agar mudah dibaca di kertas. */
    public const CHARSETS = [
        'lower' => 'abcdefghjkmnpqrstuvwxyz',
        'upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ',
        'num' => '23456789',
        'upplow' => 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',
        'mix' => 'abcdefghjkmnpqrstuvwxyz23456789',
        'mix1' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
        'mix2' => 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789',
    ];

    public function __construct(private readonly MikrotikConnector $mikrotik, private readonly AuditLogger $audit) {}

    public static function seconds(int $value, string $unit): int
    {
        return $value * match (strtolower(trim($unit))) {
            'minutes', 'minute', 'menit', 'm' => 60,
            'days', 'day', 'hari', 'd' => 86400,
            default => 3600,
        };
    }

    public static function randomString(int $length, string $type): string
    {
        $chars = self::CHARSETS[$type] ?? self::CHARSETS['mix2'];

        return implode('', array_map(fn () => $chars[random_int(0, strlen($chars) - 1)], range(1, $length)));
    }

    /**
     * @param  'vc'|'up'  $mode  vc = username sama dengan password
     * @return array{batch_id: string, count: int}
     */
    public function generate(HotspotProfile $profile, int $qty, string $mode, int $length, string $type, string $prefix, User $by): array
    {
        $batch = now()->format('ymdHis').'-'.Str::upper(Str::random(4));
        $codes = [];
        for ($attempt = 0; count($codes) < $qty && $attempt < $qty * 10; $attempt++) {
            $u = $prefix.self::randomString($length, $type);
            if (! isset($codes[$u]) && ! DB::table('radcheck')->where('username', $u)->exists() && ! Voucher::where('username', $u)->exists()) {
                $codes[$u] = $mode === 'vc' ? $u : self::randomString($length, $type);
            }
        }

        DB::transaction(function () use ($codes, $profile, $batch, $by) {
            foreach ($codes as $u => $p) {
                Voucher::create(['username' => $u, 'password' => $p, 'profile_id' => $profile->id, 'router_id' => $profile->router_id,
                    'batch_id' => $batch, 'status' => 'unused', 'generated_by' => $by->id]);
                $this->writeRadius($u, $p, $profile);
            }
        });
        $this->audit->log('voucher.generated', null, ['batch' => $batch, 'qty' => count($codes), 'profile' => $profile->name]);

        return ['batch_id' => $batch, 'count' => count($codes)];
    }

    /** Atribut RADIUS voucher: password, 1 perangkat, batas waktu, kecepatan, kuota. */
    public function writeRadius(string $username, string $password, HotspotProfile $profile): void
    {
        DB::table('radcheck')->where('username', $username)->delete();
        DB::table('radreply')->where('username', $username)->delete();

        DB::table('radcheck')->insert([
            ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password],
            ['username' => $username, 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '1'],
        ]);
        DB::table('radreply')->insert(array_values(array_filter([
            ($s = self::seconds((int) $profile->duration_value, $profile->duration_unit)) > 0 && $profile->duration_value > 0
                ? ['username' => $username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => (string) $s] : null,
            ($rate = ($profile->rate_up ?: '0').'/'.($profile->rate_down ?: '0')) !== '0/0'
                ? ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => '=', 'value' => $rate] : null,
            ['username' => $username, 'attribute' => 'Acct-Interim-Interval', 'op' => ':=', 'value' => '120'],
            $profile->quota_mb > 0
                ? ['username' => $username, 'attribute' => 'Mikrotik-Total-Limit', 'op' => ':=', 'value' => (string) ($profile->quota_mb * 1048576)] : null,
        ])));
    }

    /** Perubahan profil diterapkan ke voucher yang belum dipakai. */
    public function syncProfileToUnused(HotspotProfile $profile): int
    {
        $n = 0;
        foreach ($profile->vouchers()->where('status', 'unused')->cursor() as $v) {
            $this->writeRadius($v->username, $v->password, $profile);
            $n++;
        }

        return $n;
    }

    /** Voucher yang baru pertama kali login -> aktif, mulai masa berlaku, catat penjualan. */
    public function activateUsed(): int
    {
        $rows = DB::table('vouchers as v')->join('radacct as ra', 'ra.username', '=', 'v.username')
            ->where('v.status', 'unused')->groupBy('v.id')->selectRaw('v.id, min(ra.acctstarttime) as started')->get();

        foreach ($rows as $row) {
            DB::transaction(function () use ($row) {
                $v = Voucher::with('profile')->lockForUpdate()->find($row->id);
                if (! $v || $v->status !== 'unused') {
                    return;
                }
                $used = $row->started ? Carbon::parse($row->started) : now();
                $v->update(['status' => 'active', 'used_at' => $used, 'expired_at' => $used->copy()->addSeconds(self::seconds((int) $v->profile->validity_value, $v->profile->validity_unit))]);
                if ($v->profile->include_in_sales && ! SalesLog::where('voucher_id', $v->id)->exists()) {
                    SalesLog::create(['voucher_id' => $v->id, 'voucher_username' => $v->username, 'profile_id' => $v->profile_id, 'profile_name' => $v->profile->name,
                        'router_id' => $v->router_id, 'price' => $v->profile->price, 'sold_at' => $used]);
                }
            });
        }

        return $rows->count();
    }

    /**
     * Perbarui sisa waktu pakai & kedaluwarsakan voucher yang habis masa berlaku/waktunya.
     *
     * @return array{activated: int, expired: int}
     */
    public function expire(): array
    {
        $activated = $this->activateUsed();

        foreach (Voucher::with('profile')->where('status', 'active')->whereHas('profile', fn ($q) => $q->where('duration_value', '>', 0))->cursor() as $v) {
            $limit = self::seconds((int) $v->profile->duration_value, $v->profile->duration_unit);
            $used = (int) DB::table('radacct')->where('username', $v->username)->sum('acctsessiontime');
            $remaining = $limit - $used;
            if ($remaining <= 0) {
                $v->update(['expired_at' => now()]);
            } else {
                DB::table('radreply')->where('username', $v->username)->where('attribute', 'Session-Timeout')->update(['value' => (string) $remaining]);
            }
        }

        $expired = 0;
        foreach (Voucher::where('status', 'active')->whereNotNull('expired_at')->where('expired_at', '<=', now())->cursor() as $v) {
            $this->retire($v, 'expired');
            $expired++;
        }

        return ['activated' => $activated, 'expired' => $expired];
    }

    /** Hapus/kedaluwarsakan voucher: cabut dari RADIUS, tutup sesi, kick dari hotspot. */
    public function retire(Voucher $voucher, string $status = 'deleted'): void
    {
        DB::transaction(function () use ($voucher, $status) {
            $voucher->update(['status' => $status]);
            DB::table('radcheck')->where('username', $voucher->username)->delete();
            DB::table('radreply')->where('username', $voucher->username)->delete();
            DB::table('radacct')->where('username', $voucher->username)->whereNull('acctstoptime')
                ->update(['acctstoptime' => now(), 'acctterminatecause' => $status === 'expired' ? 'Session-Timeout' : 'Admin-Reset']);
        });

        if ($router = $voucher->router ?? Router::where('status', 'active')->first()) {
            rescue(fn () => $this->mikrotik->with($router, function (MikrotikConnection $api) use ($voucher) {
                foreach ($api->comm('/ip/hotspot/active/print', ['?user' => $voucher->username]) as $s) {
                    $api->comm('/ip/hotspot/active/remove', ['.id' => $s['.id']]);
                }
            }), report: false);
        }
    }
}

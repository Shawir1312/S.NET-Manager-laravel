<?php

namespace App\Services\Pppoe;

use App\Models\ConfigurationJob;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Radius\RadiusSync;
use App\Services\Settings\SnetSettings;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tambah/ubah/hapus/isolir pelanggan PPPoE: MikroTik secret + DB + FreeRADIUS
 * (+ WA selamat datang, + push ONT lewat mesin provisioning).
 */
class CustomerService
{
    public function __construct(
        private readonly MikrotikConnector $mikrotik,
        private readonly RadiusSync $radius,
        private readonly CustomerOntProvisioner $ont,
        private readonly WhatsAppGateway $wa,
        private readonly AuditLogger $audit,
        private readonly SnetSettings $settings,
    ) {}

    /** Password angka acak 8 digit (cukup untuk WPA2 bila dipakai juga sebagai password WiFi). */
    public static function randomPassword(): string
    {
        return (string) random_int(10_000_000, 99_999_999);
    }

    /**
     * @param  array<string, mixed>  $data  data tervalidasi dari CustomerRequest
     * @param  array{push_ont?: bool, send_welcome?: bool, pppoe_template_id?: ?int}  $options
     * @return array{customer: PppoeCustomer, notes: list<string>, job: ?ConfigurationJob}
     */
    public function create(array $data, User $by, array $options = []): array
    {
        $router = Router::findOrFail($data['router_id']);
        $password = ($data['pppoe_password'] ?? null) ?: self::randomPassword();
        $portalPassword = ($data['portal_password'] ?? null) ?: $password;

        $this->mikrotik->with($router, function (MikrotikConnection $api) use ($data) {
            if ($api->findSecret($data['pppoe_username'])) {
                // Dulu secret lama ditimpa diam-diam; sekarang ditolak agar akun orang lain tidak rusak.
                throw new MikrotikException("Username {$data['pppoe_username']} sudah ada di MikroTik. Pakai tombol Sync dari MikroTik di daftar pelanggan untuk mengimpornya.");
            }
        });

        $customer = new PppoeCustomer($this->attributes($data) + ['created_by' => $by->id]);
        $customer->portal_username = ($data['portal_username'] ?? null) ?: $data['pppoe_username'];
        $customer->portal_password = $portalPassword;
        // Default lama S.NET: WiFi = "S.NET - <nama depan>", password WiFi = password PPPoE.
        $customer->ont_wifi_pass ??= $password;
        if ($customer->ont_wifi_ssid === '') {
            $customer->ont_wifi_ssid = $this->settings->get('pppoe', 'ont_wifi1_prefix', 'S.NET - ').Str::before(trim($customer->full_name).' ', ' ');
        }

        $this->mikrotik->with($router, fn (MikrotikConnection $api) => $api->upsertSecret($customer->pppoe_username, $this->secretAttributes($customer, $password)));

        try {
            DB::transaction(function () use ($customer, $password) {
                $customer->save();
                $this->radius->syncCustomer($customer, $password);
            });
        } catch (Throwable $e) {
            // DB gagal -> jangan tinggalkan secret yatim di MikroTik.
            rescue(fn () => $this->mikrotik->with($router, fn (MikrotikConnection $api) => $api->removeSecret($customer->pppoe_username)), report: false);
            throw $e;
        }

        $this->audit->log('pppoe.customer_created', $customer, ['username' => $customer->pppoe_username, 'router' => $router->name]);

        $notes = ["Pelanggan {$customer->full_name} ditambahkan (user: {$customer->pppoe_username})."];
        $job = $this->maybePushOnt($customer, $by, $password, $options, $notes);

        if (($options['send_welcome'] ?? false) && $customer->phone !== '') {
            $res = $this->wa->sendTemplate('welcome_customer', $customer->phone, $customer->toArray() + [
                'portal_username' => $customer->portal_username, 'portal_password' => $portalPassword,
            ], $customer->id);
            $notes[] = $res === null ? 'Template WA selamat datang tidak ada.' : ($res['success'] ? "WA selamat datang terkirim ke {$customer->phone}." : 'WA gagal: '.$res['message']);
        }

        return ['customer' => $customer, 'notes' => $notes, 'job' => $job];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{push_ont?: bool, pppoe_template_id?: ?int}  $options
     * @return array{customer: PppoeCustomer, notes: list<string>, job: ?ConfigurationJob}
     */
    public function update(PppoeCustomer $customer, array $data, User $by, array $options = []): array
    {
        $oldUsername = $customer->pppoe_username;
        $oldStatus = $customer->status;
        $oldRouter = $customer->router;
        $password = ($data['pppoe_password'] ?? null) ?: null;

        $customer->fill($this->attributes($data));
        if (filled($data['portal_username'] ?? null)) {
            $customer->portal_username = $data['portal_username'];
        }
        if (filled($data['portal_password'] ?? null)) {
            $customer->portal_password = $data['portal_password'];
        }
        if ($customer->isDirty('status')) {
            $customer->isolated_at = $customer->status === 'isolated' ? now() : null;
            $customer->isolated_reason = $customer->status === 'isolated' ? 'Manual isolated' : '';
        }

        $router = Router::findOrFail($customer->router_id);
        if ($oldRouter && $oldRouter->id !== $router->id) {
            $this->mikrotik->with($oldRouter, fn (MikrotikConnection $api) => $api->removeSecret($oldUsername));
        }
        $this->mikrotik->with($router, function (MikrotikConnection $api) use ($customer, $oldUsername, $oldStatus, $password) {
            $attrs = $this->secretAttributes($customer, $password);
            $existing = $api->findSecret($oldUsername);
            if ($existing) {
                $api->comm('/ppp/secret/set', ['.id' => $existing['.id'], 'name' => $customer->pppoe_username] + $attrs);
            } else {
                $api->upsertSecret($customer->pppoe_username, $attrs + ['password' => $password ?? $this->radius->passwordOf($oldUsername) ?? self::randomPassword()]);
            }
            if ($oldUsername !== $customer->pppoe_username || $oldStatus !== $customer->status || $password) {
                $api->kickActive($oldUsername);
            }
        });

        DB::transaction(function () use ($customer, $password, $oldUsername) {
            $customer->save();
            $this->radius->syncCustomer($customer, $password, $oldUsername);
        });
        $this->audit->log('pppoe.customer_updated', $customer, ['username' => $customer->pppoe_username]);

        $notes = ["Pelanggan {$customer->full_name} diperbarui."];
        $job = $this->maybePushOnt($customer, $by, $password ?? $this->radius->passwordOf($customer->pppoe_username), $options, $notes);

        return ['customer' => $customer, 'notes' => $notes, 'job' => $job];
    }

    /**
     * Impor PPP secret dari MikroTik: secret baru -> pelanggan baru, yang sudah ada -> profil/status disamakan.
     * Password ikut disalin ke FreeRADIUS. Harga diambil dari pelanggan lain dengan profil yang sama.
     *
     * @return array{inserted: int, updated: int}
     */
    public function syncFromMikrotik(Router $router, User $by): array
    {
        $secrets = $this->mikrotik->with($router, fn (MikrotikConnection $api) => $api->comm('/ppp/secret/print'));
        $isolir = (string) $this->settings->get('pppoe', 'isolir_profile', 'isolir');
        $prices = PppoeCustomer::where('router_id', $router->id)->where('monthly_price', '>', 0)->pluck('monthly_price', 'profile');
        $inserted = $updated = 0;

        foreach ($secrets as $s) {
            $name = trim($s['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $profile = trim($s['profile'] ?? 'default');
            $status = ($s['disabled'] ?? 'false') === 'true' || $profile === $isolir ? 'isolated' : 'active';
            $customer = PppoeCustomer::firstOrNew(['router_id' => $router->id, 'pppoe_username' => $name]);
            $isNew = ! $customer->exists;

            if ($isNew) {
                $customer->fill(['full_name' => trim($s['comment'] ?? '') ?: $name, 'profile' => $profile === $isolir ? 'default' : $profile,
                    'monthly_price' => (int) ($prices[$profile] ?? 0), 'due_day' => 1, 'created_by' => $by->id]);
            } elseif ($profile !== $isolir) {
                $customer->profile = $profile;
            }
            $customer->status = $status;
            if (! $customer->isDirty() && ! $isNew) {
                continue;
            }
            $customer->save();
            $this->radius->syncCustomer($customer, ($s['password'] ?? '') ?: null);
            $isNew ? $inserted++ : $updated++;
        }
        $this->audit->log('pppoe.synced_from_mikrotik', null, ['router' => $router->name, 'inserted' => $inserted, 'updated' => $updated]);

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    public function delete(PppoeCustomer $customer): void
    {
        rescue(fn () => $this->mikrotik->with($customer->router, function (MikrotikConnection $api) use ($customer) {
            $api->removeSecret($customer->pppoe_username);
            $api->kickActive($customer->pppoe_username);
        }), report: false);
        DB::transaction(function () use ($customer) {
            $this->radius->forget($customer->pppoe_username);
            $customer->delete();
        });
        $this->audit->log('pppoe.customer_deleted', null, ['username' => $customer->pppoe_username]);
    }

    /** Ubah status (active/isolated/suspended) tanpa form lengkap, mis. isolir otomatis & bayar lunas. */
    public function setStatus(PppoeCustomer $customer, string $status, string $reason = ''): bool
    {
        $customer->forceFill([
            'status' => $status,
            'isolated_at' => $status === 'isolated' ? now() : null,
            'isolated_reason' => $status === 'isolated' ? $reason : '',
        ])->save();
        $this->radius->syncCustomer($customer);

        $mikrotikOk = rescue(fn () => $this->mikrotik->with($customer->router, function (MikrotikConnection $api) use ($customer) {
            $api->upsertSecret($customer->pppoe_username, $this->secretAttributes($customer, null));
            $api->kickActive($customer->pppoe_username);

            return true;
        }), false, report: false);

        $this->audit->log('pppoe.status_changed', $customer, ['status' => $status, 'reason' => $reason, 'mikrotik' => $mikrotikOk]);

        return (bool) $mikrotikOk;
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $isFree = (bool) ($data['is_free'] ?? false);

        return [
            'router_id' => (int) $data['router_id'],
            'pppoe_username' => $data['pppoe_username'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? '',
            'address' => $data['address'] ?? null,
            'profile' => $data['profile'],
            'monthly_price' => $isFree ? 0 : (int) $data['monthly_price'],
            'is_free' => $isFree,
            'due_day' => (int) $data['due_day'],
            'status' => $data['status'] ?? 'active',
            'ont_sn' => strtoupper(trim($data['ont_sn'] ?? '')),
            'ont_vlan' => (int) ($data['ont_vlan'] ?? 0), // 0 = ikut VLAN PPPoE di template
            'ont_wifi_ssid' => $data['ont_wifi_ssid'] ?? '',
            'notes' => $data['notes'] ?? null,
        ] + (filled($data['ont_wifi_pass'] ?? null) ? ['ont_wifi_pass' => $data['ont_wifi_pass']] : []);
    }

    /** @return array<string, string> */
    private function secretAttributes(PppoeCustomer $customer, ?string $password): array
    {
        return array_filter([
            'profile' => $this->radius->groupFor($customer),
            'disabled' => $customer->status === 'suspended' ? 'yes' : 'no',
            'password' => $password,
        ], fn ($v) => $v !== null);
    }

    /** @param  list<string>  $notes */
    private function maybePushOnt(PppoeCustomer $customer, User $by, ?string $password, array $options, array &$notes): ?ConfigurationJob
    {
        if (! ($options['push_ont'] ?? false) || $customer->ont_sn === '') {
            return null;
        }

        try {
            $r = $this->ont->push($customer, $by, $password, $options['pppoe_template_id'] ?? null);
            $notes[] = "Konfigurasi ONT {$customer->ont_sn} diantrekan. Pantau statusnya di tab Config ONT.";
            array_push($notes, ...$r['warnings']);

            return $r['job'];
        } catch (ProvisioningException $e) {
            $notes[] = 'ONT belum dikonfigurasi: '.$e->getMessage();

            return null;
        }
    }
}

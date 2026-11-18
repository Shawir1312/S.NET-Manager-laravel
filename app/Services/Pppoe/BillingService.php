<?php

namespace App\Services\Pppoe;

use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\GenieACS\GenieACSService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Settings\SnetSettings;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Tagihan bulanan PPPoE: status lunas, catat bayar, isolir & buka isolir otomatis, pengingat WA.
 * Aturan sama dengan process/cron_pppoe.php & cron_pppoe_reminder.php lama.
 */
class BillingService
{
    public const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly CustomerService $customers,
        private readonly WhatsAppGateway $wa,
        private readonly SnetSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function isPaid(PppoeCustomer $customer, int $month, int $year): bool
    {
        return $customer->payments()->where('period_month', $month)->where('period_year', $year)
            // Sama dengan check_payment() lama: lunas, tunai, atau status gateway selain pending/batal.
            ->where(fn ($q) => $q->where('midtrans_status', 'paid')->orWhere('payment_method', 'cash')
                ->orWhere(fn ($w) => $w->whereNotNull('midtrans_status')->whereNotIn('midtrans_status', ['pending', 'cancel', 'deny', 'expire'])))
            ->exists();
    }

    /** Tanggal jatuh tempo pada bulan tertentu (tgl 29-31 dipotong ke akhir bulan). */
    public static function dueDate(PppoeCustomer $customer, int $month, int $year): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return $first->setDay(min(max(1, $customer->due_day), $first->daysInMonth));
    }

    /**
     * Tagihan yang belum lunas: bulan lalu (tunggakan) dan bulan ini. Nominal SELALU dari data pelanggan.
     *
     * @return list<array{month: int, year: int, label: string, amount: int, due: CarbonImmutable, is_arrear: bool}>
     */
    public function unpaidBills(PppoeCustomer $customer, ?CarbonImmutable $today = null): array
    {
        if ($customer->is_free || $customer->monthly_price <= 0) {
            return [];
        }
        $today ??= CarbonImmutable::today();
        $bills = [];
        foreach ([$today->subMonthNoOverflow(), $today] as $period) {
            if ($this->isPaid($customer, $period->month, $period->year)) {
                continue;
            }
            $due = self::dueDate($customer, $period->month, $period->year);
            $bills[] = [
                'month' => $period->month, 'year' => $period->year, 'label' => self::MONTHS[$period->month].' '.$period->year,
                'amount' => (int) $customer->monthly_price, 'due' => $due, 'is_arrear' => $today->gte($due),
            ];
        }

        return $bills;
    }

    /** Alasan isolir bila pelanggan menunggak melewati masa tenggang, null bila aman. */
    public function overdueReason(PppoeCustomer $customer, ?CarbonImmutable $today = null): ?string
    {
        if ($customer->is_free || $customer->monthly_price <= 0) {
            return null;
        }
        $today ??= CarbonImmutable::today();
        $grace = (int) $this->settings->get('pppoe', 'isolir_grace_days', '3');

        foreach ([$today->subMonthNoOverflow(), $today] as $period) {
            $due = self::dueDate($customer, $period->month, $period->year);
            if ($today->gte($due->addDays($grace)) && ! $this->isPaid($customer, $period->month, $period->year)) {
                $late = (int) $due->diffInDays($today);

                return "Auto-isolir: Menunggak tagihan bulan {$period->month}/{$period->year}".($late > 0 ? " (Terlambat {$late} hari)" : ' (Jatuh tempo hari ini)');
            }
        }

        return null;
    }

    /** Catat pembayaran tunai/transfer oleh kasir; buka isolir & kirim kwitansi WA. */
    public function recordPayment(PppoeCustomer $customer, int $amount, int $month, int $year, string $method, User $by, string $notes = '', bool $sendWa = true): PppoePayment
    {
        $payment = $customer->payments()->create([
            'amount' => $amount, 'payment_method' => $method, 'payment_gateway' => null,
            'midtrans_order_id' => 'MANUAL-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'midtrans_status' => 'paid', 'period_month' => $month, 'period_year' => $year,
            'paid_at' => now(), 'notes' => $notes ?: "Diterima oleh {$by->name} (".ucfirst($method).')', 'created_by' => $by->id,
        ]);
        $this->afterPaid($payment, $by->name, $sendWa);
        $this->audit->log('pppoe.payment_recorded', $payment, ['customer' => $customer->pppoe_username, 'amount' => $amount, 'period' => "{$month}/{$year}"]);

        return $payment;
    }

    /** Dipanggil setelah pembayaran (manual/gateway) dinyatakan lunas. */
    public function afterPaid(PppoePayment $payment, string $collector, bool $sendWa = true): void
    {
        $customer = $payment->customer;
        $customer->forceFill(['last_paid_at' => now()->toDateString(), 'last_paid_amount' => $payment->amount])->save();

        if ($customer->status === 'isolated' && $this->overdueReason($customer) === null) {
            $this->reactivate($customer);
        }
        if ($sendWa && $customer->phone !== '') {
            $this->wa->sendTemplate('payment_success', $customer->phone, $customer->toArray() + [
                'amount' => $payment->amount, 'month_name' => self::MONTHS[$payment->period_month].' '.$payment->period_year,
                'no_invoice' => $payment->midtrans_order_id, 'payment_method' => $payment->payment_method, 'collector_name' => $collector,
                'link_receipt' => route('receipt.show', ['payment' => $payment->id, 'signature' => self::receiptSignature($payment)]),
                'waktu_bayar' => $payment->paid_at?->format('d M Y, H:i').' WIB',
            ], $customer->id);
        }
    }

    /** Link kwitansi publik ditandatangani agar tidak bisa ditebak (dulu cukup ?id=1,2,3…). */
    public static function receiptSignature(PppoePayment $payment): string
    {
        return substr(hash_hmac('sha256', 'receipt:'.$payment->id, (string) config('app.key')), 0, 32);
    }

    /** Buka isolir + reboot ONT (hanya mode produksi) agar pelanggan langsung dapat IP normal. */
    public function reactivate(PppoeCustomer $customer): void
    {
        $this->customers->setStatus($customer, 'active');

        $device = $customer->ontDevice();
        if ($device && ProvisioningService::isProduction()) {
            rescue(fn () => GenieACSService::forDevice($device)->createTask($device->genieacs_id, ['name' => 'reboot']), report: false);
        }
    }

    /**
     * Isolir pelanggan yang menunggak & buka isolir yang sudah lunas.
     *
     * @return array{isolated: int, reactivated: int}
     */
    public function runAutoIsolir(?CarbonImmutable $today = null): array
    {
        $isolated = $reactivated = 0;

        PppoeCustomer::where('status', 'active')->where('is_free', false)->where('monthly_price', '>', 0)
            ->with('router')->chunkById(200, function ($batch) use ($today, &$isolated) {
                foreach ($batch as $c) {
                    if ($reason = $this->overdueReason($c, $today)) {
                        $this->customers->setStatus($c, 'isolated', $reason);
                        $isolated++;
                        if ($c->phone !== '') {
                            $this->wa->sendTemplate('isolir', $c->phone, $c->toArray(), $c->id);
                        }
                    }
                }
            });

        PppoeCustomer::where('status', 'isolated')->with('router')->chunkById(200, function ($batch) use ($today, &$reactivated) {
            foreach ($batch as $c) {
                if ($this->overdueReason($c, $today) === null) {
                    $this->reactivate($c);
                    $reactivated++;
                }
            }
        });

        return ['isolated' => $isolated, 'reactivated' => $reactivated];
    }

    /**
     * Pengingat WA H-3, H-1 dan hari H jatuh tempo bulan berjalan.
     *
     * @return array{reminder_h3: int, reminder_h1: int, reminder_h0: int}
     */
    public function sendReminders(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $sent = ['reminder_h3' => 0, 'reminder_h1' => 0, 'reminder_h0' => 0];

        PppoeCustomer::where('status', 'active')->where('is_free', false)->where('monthly_price', '>', 0)->where('phone', '!=', '')
            ->chunkById(200, function ($batch) use ($today, &$sent) {
                foreach ($batch as $c) {
                    $due = self::dueDate($c, $today->month, $today->year);
                    $code = match ((int) $today->diffInDays($due, false)) {
                        3 => 'reminder_h3', 1 => 'reminder_h1', 0 => 'reminder_h0', default => null,
                    };
                    if ($code && ! $this->isPaid($c, $today->month, $today->year)) {
                        $res = $this->wa->sendTemplate($code, $c->phone, $c->toArray() + ['month_name' => self::MONTHS[$today->month].' '.$today->year, 'due_date' => $due->format('d M Y')], $c->id);
                        $sent[$code] += (int) ($res['success'] ?? false);
                    }
                }
            });

        return $sent;
    }
}

<?php

namespace App\Services\Payment;

use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Services\Audit\AuditLogger;
use App\Services\Pppoe\BillingService;
use App\Services\Settings\SnetSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pembayaran tagihan online lewat Midtrans Snap / Tripay (port dari include/PaymentGateway.php).
 * Perbaikan keamanan: nominal SELALU dari data pelanggan (dulu dari browser), dan webhook
 * hanya melunasi bila nominal dari gateway sama dengan nominal tagihan.
 */
class PaymentGatewayService
{
    public function __construct(
        private readonly SnetSettings $settings,
        private readonly BillingService $billing,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<'midtrans'|'tripay'> */
    public function available(): array
    {
        $active = $this->settings->get('pppoe', 'active_payment_gateway', 'none');
        $out = [];
        if (in_array($active, ['midtrans', 'both'], true) && $this->settings->secret('pppoe', 'midtrans_server_key') && $this->settings->get('pppoe', 'midtrans_client_key')) {
            $out[] = 'midtrans';
        }
        if (in_array($active, ['tripay', 'both'], true) && $this->settings->secret('pppoe', 'tripay_api_key') && $this->settings->secret('pppoe', 'tripay_private_key') && $this->settings->get('pppoe', 'tripay_merchant_code')) {
            $out[] = 'tripay';
        }

        return $out;
    }

    private function tripayBase(): string
    {
        return $this->settings->get('pppoe', 'tripay_mode', 'sandbox') === 'production' ? 'https://tripay.co.id/api/' : 'https://tripay.co.id/api-sandbox/';
    }

    private function midtransBase(): string
    {
        return $this->settings->get('pppoe', 'midtrans_mode', 'sandbox') === 'production' ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function midtransApi(): string
    {
        return $this->settings->get('pppoe', 'midtrans_mode', 'sandbox') === 'production' ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    /** @return list<array{code: string, name: string, group: string}> */
    public function tripayChannels(): array
    {
        $res = rescue(fn () => Http::timeout(5)->withToken((string) $this->settings->secret('pppoe', 'tripay_api_key'))->get($this->tripayBase().'merchant/payment-channel')->json(), [], report: false);

        return collect($res['data'] ?? [])->filter(fn ($c) => $c['active'] ?? true)
            ->map(fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'group' => $c['group'] ?? 'Lainnya'])->values()->all()
            ?: [['code' => 'QRIS', 'name' => 'QRIS', 'group' => 'E-Wallet'], ['code' => 'BRIVA', 'name' => 'BRI Virtual Account', 'group' => 'Virtual Account'], ['code' => 'BNIVA', 'name' => 'BNI Virtual Account', 'group' => 'Virtual Account']];
    }

    /**
     * Mulai pembayaran satu periode tagihan yang belum lunas.
     *
     * @return array<string, mixed> data untuk front-end (token Snap atau instruksi Tripay)
     */
    public function start(PppoeCustomer $customer, int $month, int $year, string $gateway, string $channel = 'QRIS'): array
    {
        if (! in_array($gateway, $this->available(), true)) {
            throw new RuntimeException('Payment gateway tidak aktif.');
        }
        $bill = collect($this->billing->unpaidBills($customer))->first(fn ($b) => $b['month'] === $month && $b['year'] === $year);
        if (! $bill) {
            throw new RuntimeException('Tagihan periode ini tidak ada atau sudah lunas.');
        }

        $amount = $bill['amount'];
        $orderId = 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $customer->pppoe_username), 0, 6)).'-'.random_int(1000, 9999);
        $item = "Tagihan Internet {$bill['label']} ({$customer->pppoe_username})";
        $payment = $customer->payments()->create([
            'amount' => $amount, 'payment_method' => $gateway, 'payment_gateway' => $gateway, 'midtrans_order_id' => $orderId,
            'midtrans_status' => 'pending', 'period_month' => $month, 'period_year' => $year, 'notes' => "Bayar Portal ({$bill['label']}) - {$gateway}",
        ]);
        $return = route('portal.home', ['paid' => 1]);

        try {
            if ($gateway === 'tripay') {
                $merchant = (string) $this->settings->get('pppoe', 'tripay_merchant_code');
                $res = Http::timeout(10)->withToken((string) $this->settings->secret('pppoe', 'tripay_api_key'))->post($this->tripayBase().'transaction/create', [
                    'method' => $channel, 'merchant_ref' => $orderId, 'amount' => $amount,
                    'customer_name' => $customer->full_name ?: 'Pelanggan', 'customer_email' => Str::slug($customer->pppoe_username).'@pelanggan.local',
                    'customer_phone' => preg_replace('/\D/', '', $customer->phone) ?: '0800000000',
                    'order_items' => [['sku' => "TAGIHAN-{$month}{$year}", 'name' => $item, 'price' => $amount, 'quantity' => 1]],
                    'return_url' => $return, 'expired_time' => now()->addDay()->timestamp,
                    'signature' => hash_hmac('sha256', $merchant.$orderId.$amount, (string) $this->settings->secret('pppoe', 'tripay_private_key')),
                ])->json();
                $d = $res['data'] ?? null;
                if (! ($res['success'] ?? false) || ! $d) {
                    throw new RuntimeException($res['message'] ?? 'Gagal membuat transaksi Tripay.');
                }
                $payment->update(['midtrans_tx_id' => $d['reference'] ?? null, 'checkout_url' => $d['checkout_url'] ?? null, 'pay_code' => $d['pay_code'] ?? null, 'qr_url' => $d['qr_url'] ?? null]);

                return ['gateway' => 'tripay', 'order_id' => $orderId, 'amount' => $d['amount'] ?? $amount, 'payment_name' => $d['payment_name'] ?? $channel,
                    'checkout_url' => $d['checkout_url'] ?? null, 'pay_code' => $d['pay_code'] ?? null, 'qr_url' => $d['qr_url'] ?? null, 'instructions' => $d['instructions'] ?? []];
            }

            $res = Http::timeout(10)->withBasicAuth((string) $this->settings->secret('pppoe', 'midtrans_server_key'), '')->post($this->midtransBase().'/snap/v1/transactions', [
                'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
                'customer_details' => ['first_name' => $customer->full_name, 'phone' => $customer->phone],
                'item_details' => [['id' => "INET-{$month}{$year}", 'price' => $amount, 'quantity' => 1, 'name' => Str::limit($item, 50, '')]],
                'callbacks' => ['finish' => $return],
            ])->json();
            if (empty($res['token'])) {
                throw new RuntimeException($res['error_messages'][0] ?? 'Gagal membuat transaksi Midtrans.');
            }

            return ['gateway' => 'midtrans', 'order_id' => $orderId, 'token' => $res['token'], 'client_key' => $this->settings->get('pppoe', 'midtrans_client_key'),
                'snap_js' => $this->midtransBase().'/snap/snap.js'];
        } catch (ConnectionException $e) {
            $payment->update(['midtrans_status' => 'cancel']);
            throw new RuntimeException('Koneksi ke payment gateway gagal.');
        } catch (RuntimeException $e) {
            $payment->update(['midtrans_status' => 'cancel']);
            throw $e;
        }
    }

    /** Cek status ke gateway (polling dari portal). */
    public function refreshStatus(PppoePayment $payment): string
    {
        if ($payment->midtrans_status !== 'pending') {
            return (string) $payment->midtrans_status;
        }
        if ($payment->payment_method === 'tripay') {
            $d = rescue(fn () => Http::timeout(5)->withToken((string) $this->settings->secret('pppoe', 'tripay_api_key'))
                ->get($this->tripayBase().'transaction/detail', ['reference' => $payment->midtrans_tx_id ?: $payment->midtrans_order_id])->json('data'), null, report: false);
            if (strtoupper($d['status'] ?? '') === 'PAID') {
                // Nominal Tripay dikunci oleh signature saat transaksi dibuat (merchant+order+amount), jadi tidak dicek ulang.
                $this->markPaid($payment, 'tripay', $d['reference'] ?? '');
            }
        } elseif ($payment->payment_method === 'midtrans') {
            $d = rescue(fn () => Http::timeout(5)->withBasicAuth((string) $this->settings->secret('pppoe', 'midtrans_server_key'), '')
                ->get($this->midtransApi()."/v2/{$payment->midtrans_order_id}/status")->json(), null, report: false);
            if (in_array($d['transaction_status'] ?? '', ['settlement', 'capture'], true) && in_array($d['fraud_status'] ?? '', ['accept', ''], true)) {
                $this->markPaid($payment, 'midtrans', $d['transaction_id'] ?? '', $d['gross_amount'] ?? null);
            }
        }

        return (string) $payment->fresh()->midtrans_status;
    }

    /** Webhook Tripay: signature HMAC raw body + nominal harus cocok. */
    public function handleTripay(string $raw, string $signature, string $event): bool
    {
        $key = (string) $this->settings->secret('pppoe', 'tripay_private_key');
        if ($key === '' || ! hash_equals(hash_hmac('sha256', $raw, $key), $signature)) {
            return false;
        }
        $d = json_decode($raw, true) ?: [];
        if ($event !== 'payment_status' || empty($d['merchant_ref'])) {
            return true;
        }
        $payment = PppoePayment::where('midtrans_order_id', $d['merchant_ref'])->first();
        match (strtoupper($d['status'] ?? '')) {
            'PAID' => $payment && $this->markPaid($payment, 'tripay', $d['reference'] ?? ''),
            'EXPIRED' => $payment?->update(['midtrans_status' => 'expire']),
            'FAILED', 'REFUND' => $payment?->update(['midtrans_status' => 'cancel']),
            default => null,
        };

        return true;
    }

    /** Webhook Midtrans: signature SHA512(order_id+status_code+gross_amount+server_key). */
    public function handleMidtrans(array $d): bool
    {
        $key = (string) $this->settings->secret('pppoe', 'midtrans_server_key');
        $expected = hash('sha512', ($d['order_id'] ?? '').($d['status_code'] ?? '').($d['gross_amount'] ?? '').$key);
        if ($key === '' || ! hash_equals($expected, (string) ($d['signature_key'] ?? ''))) {
            return false;
        }
        $payment = PppoePayment::where('midtrans_order_id', $d['order_id'] ?? '')->first();
        $status = $d['transaction_status'] ?? '';
        if ($payment && in_array($status, ['settlement', 'capture'], true) && in_array($d['fraud_status'] ?? '', ['accept', ''], true)) {
            $this->markPaid($payment, 'midtrans', $d['transaction_id'] ?? '', $d['gross_amount'] ?? null);
        } elseif ($payment && in_array($status, ['cancel', 'deny', 'expire'], true) && $payment->midtrans_status === 'pending') {
            $payment->update(['midtrans_status' => $status]);
        }

        return true;
    }

    /** Tandai lunas sekali saja (idempoten); bila gateway mengirim nominal, harus sama dengan tagihan. */
    public function markPaid(PppoePayment $payment, string $gateway, string $txId, mixed $gatewayAmount = null): bool
    {
        if ($gatewayAmount !== null && (int) round((float) $gatewayAmount) !== (int) $payment->amount) {
            $this->audit->log('payment.amount_mismatch', $payment, ['gateway' => $gateway, 'expected' => $payment->amount, 'received' => $gatewayAmount]);

            return false;
        }
        $changed = DB::transaction(function () use ($payment, $gateway, $txId) {
            $fresh = PppoePayment::lockForUpdate()->find($payment->id);
            if (! $fresh || $fresh->midtrans_status === 'paid') {
                return false;
            }
            $fresh->update(['midtrans_status' => 'paid', 'payment_gateway' => $gateway, 'midtrans_tx_id' => $txId ?: $fresh->midtrans_tx_id, 'paid_at' => now()]);

            return true;
        });
        if ($changed) {
            $this->billing->afterPaid($payment->fresh(), 'Sistem Online ('.ucfirst($gateway).')');
            $this->audit->log('payment.paid_online', $payment, ['gateway' => $gateway, 'amount' => $payment->amount]);
        }

        return $changed;
    }
}

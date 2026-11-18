<?php

namespace App\Http\Controllers;

use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Models\Router;
use App\Services\Pppoe\BillingService;
use App\Services\Settings\SnetSettings;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    /** Riwayat Pembayaran PPPoE (filter router, bulan, tahun, metode, status, cari). */
    public function index(Request $request): View
    {
        Gate::authorize('operate');
        $base = $this->filtered($request);

        return view('payments.index', [
            'payments' => (clone $base)->with('customer.router:id,name')->orderByDesc('paid_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'stats' => [
                'total_paid' => (clone $base)->where('midtrans_status', 'paid')->sum('amount'),
                'count_paid' => (clone $base)->where('midtrans_status', 'paid')->count(),
                'count_pending' => (clone $base)->where('midtrans_status', 'pending')->count(),
            ],
            'routers' => Router::orderBy('name')->get(['id', 'name']),
            'customers' => PppoeCustomer::orderBy('full_name')->get(['id', 'full_name', 'pppoe_username', 'monthly_price']),
            'selMonth' => (int) $request->query('month', now()->month),
            'selYear' => (int) $request->query('year', now()->year),
        ]);
    }

    /** Kirim ulang kwitansi pembayaran ke WhatsApp pelanggan. */
    public function sendReceipt(PppoePayment $payment, WhatsAppGateway $wa): RedirectResponse
    {
        Gate::authorize('operate');
        $c = $payment->customer;
        $res = $wa->sendTemplate('payment_success', (string) $c->phone, $c->toArray() + [
            'amount' => $payment->amount, 'month_name' => BillingService::MONTHS[$payment->period_month].' '.$payment->period_year,
            'no_invoice' => $payment->midtrans_order_id, 'payment_method' => $payment->payment_method,
            'link_receipt' => route('receipt.show', ['payment' => $payment, 'signature' => BillingService::receiptSignature($payment)]),
            'waktu_bayar' => $payment->paid_at?->format('d M Y, H:i').' WIB',
        ], $c->id);

        return back()->with('status', $res && $res['success'] ? "Kwitansi dikirim ke WhatsApp {$c->full_name}." : 'Gagal kirim WhatsApp: '.($res['message'] ?? 'template tidak ada'));
    }

    /** Catat bayar kasir (form/modal S.NET: period_month + period_year, atau period Y-m). */
    public function store(Request $request, BillingService $billing): RedirectResponse
    {
        Gate::authorize('operate');
        if ($request->filled('period') && ! $request->filled('period_month')) {
            [$y, $m] = array_pad(explode('-', (string) $request->input('period')), 2, null);
            $request->merge(['period_year' => $y, 'period_month' => $m]);
        }
        $d = $request->validate([
            'customer_id' => ['required', 'exists:pppoe_customers,id'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year' => ['required', 'integer', 'between:2020,2099'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,transfer,qris,other'],
            'notes' => ['nullable', 'string', 'max:255'],
            'collector_name' => ['nullable', 'string', 'max:100'],
        ], [], ['period_month' => 'periode']);
        $customer = PppoeCustomer::findOrFail($d['customer_id']);
        [$month, $year] = [(int) $d['period_month'], (int) $d['period_year']];

        if ($billing->isPaid($customer, $month, $year)) {
            return back()->withErrors(['period' => "Tagihan {$month}/{$year} sudah lunas."]);
        }
        $collector = $d['collector_name'] ?? $request->user()->name;
        $notes = ($d['notes'] ?? '') ?: "Diterima oleh {$collector} (".ucfirst($d['payment_method']).')';
        $billing->recordPayment($customer, $d['amount'], $month, $year, $d['payment_method'], $request->user(), $notes, $request->boolean('send_wa'));

        return back()->with('status', "Pembayaran untuk '{$customer->full_name}' (Periode {$month}/{$year}) sebesar Rp ".number_format($d['amount'], 0, ',', '.').' berhasil dicatat!');
    }

    public function destroy(PppoePayment $payment): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $payment->delete();

        return back()->with('status', 'Pembayaran dihapus.');
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('operate');
        $rows = $this->filtered($request)->with('customer:id,full_name,pppoe_username')->orderBy('id')->cursor();
        [$year, $month] = [(int) $request->query('year', now()->year), (int) $request->query('month', now()->month)];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tanggal', 'Pelanggan', 'Username', 'Periode', 'Nominal', 'Metode', 'Status', 'No. Invoice']);
            foreach ($rows as $p) {
                fputcsv($out, [$p->paid_at?->format('Y-m-d H:i'), $p->customer?->full_name, $p->customer?->pppoe_username, "{$p->period_month}/{$p->period_year}", $p->amount, $p->payment_method, $p->midtrans_status, $p->midtrans_order_id]);
            }
            fclose($out);
        }, "pembayaran-{$year}-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Kwitansi publik untuk pelanggan; hanya bisa dibuka dengan tanda tangan dari link WA/portal. */
    public function receipt(Request $request, PppoePayment $payment, SnetSettings $settings): View
    {
        abort_unless(hash_equals(BillingService::receiptSignature($payment), (string) $request->query('signature')), 404);

        return view('payments.receipt', ['payment' => $payment->load('customer.router'), 'company' => $settings->all('pppoe')]);
    }

    private function filtered(Request $request): Builder
    {
        $month = (int) $request->query('month', now()->month);
        $q = trim((string) $request->query('q'));

        return PppoePayment::query()
            ->when($month > 0, fn ($b) => $b->where('period_month', $month))
            ->where('period_year', (int) $request->query('year', now()->year))
            ->when($request->integer('router_id') > 0, fn ($b) => $b->whereHas('customer', fn ($c) => $c->where('router_id', $request->integer('router_id'))))
            ->when($request->filled('method'), fn ($b) => $b->where('payment_method', $request->query('method')))
            ->when($request->filled('status'), fn ($b) => $b->where('midtrans_status', $request->query('status')))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('midtrans_order_id', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$q}%")->orWhere('pppoe_username', 'like', "%{$q}%"))));
    }
}

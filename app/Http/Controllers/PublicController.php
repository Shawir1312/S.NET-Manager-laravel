<?php

namespace App\Http\Controllers;

use App\Models\PppoeCustomer;
use App\Models\SalesLog;
use App\Models\Voucher;
use App\Services\Hotspot\VoucherService;
use App\Services\Payment\PaymentGatewayService;
use App\Services\Pppoe\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/** Halaman tanpa login: isolir (dibuka router saat pelanggan diisolir), cek & perpanjang voucher. */
class PublicController extends Controller
{
    /**
     * Pelanggan dikenali HANYA dari IP sesi PPPoE aktifnya (tidak lagi dari ?user= yang bisa
     * dipakai mengintip data orang lain, dan tidak dari header X-Forwarded-For palsu).
     */
    private function customerFromIp(Request $request): ?PppoeCustomer
    {
        $username = DB::table('radacct')->where('framedipaddress', $request->ip())->whereNull('acctstoptime')->latest('radacctid')->value('username');

        return $username ? PppoeCustomer::where('pppoe_username', $username)->first() : null;
    }

    public function isolir(Request $request, BillingService $billing, PaymentGatewayService $gateways): View
    {
        $c = $this->customerFromIp($request);

        return view('portal.isolir', [
            'customer' => $c, 'bills' => $c ? $billing->unpaidBills($c) : [], 'gateways' => $gateways->available(),
            'tripayChannels' => in_array('tripay', $gateways->available(), true) ? $gateways->tripayChannels() : [],
        ]);
    }

    public function isolirPay(Request $request, PaymentGatewayService $gateways): JsonResponse
    {
        $c = $this->customerFromIp($request);
        abort_unless($c, 404);
        $d = $request->validate(['period' => ['required', 'date_format:Y-m'], 'gateway' => ['required', 'in:midtrans,tripay'], 'channel' => ['nullable', 'string', 'max:30', 'alpha_num']]);
        [$year, $month] = array_map('intval', explode('-', $d['period']));
        try {
            return response()->json($gateways->start($c, $month, $year, $d['gateway'], $d['channel'] ?? 'QRIS'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function voucher(Request $request): View
    {
        $voucher = null;
        $remaining = null;
        if ($request->filled('code')) {
            $code = $request->validate(['code' => ['string', 'max:64']])['code'];
            $voucher = Voucher::with('profile:id,name,display_name,duration_value,duration_unit')->where('username', $code)->where('status', '!=', 'deleted')->first();
            if ($voucher && $voucher->profile->duration_value > 0) {
                $remaining = max(0, VoucherService::seconds((int) $voucher->profile->duration_value, $voucher->profile->duration_unit)
                    - (int) DB::table('radacct')->where('username', $voucher->username)->sum('acctsessiontime'));
            }
        }

        return view('portal.voucher', compact('voucher', 'remaining'));
    }

    /** Perpanjang: voucher lama (aktif/expired) diganti voucher baru dengan paket yang sama. */
    public function extend(Request $request, VoucherService $vouchers): View
    {
        $d = $request->validate(['old_code' => ['required', 'string', 'max:64'], 'new_code' => ['required', 'string', 'max:64', 'different:old_code']]);
        $old = Voucher::with('profile')->where('username', $d['old_code'])->whereIn('status', ['active', 'expired'])->first();
        $new = Voucher::where('username', $d['new_code'])->where('status', 'unused')->first();

        $error = match (true) {
            ! $old => 'Voucher lama tidak ditemukan atau belum pernah dipakai.',
            ! $new => 'Voucher baru tidak ditemukan atau sudah terpakai.',
            $old->profile_id !== $new->profile_id => 'Paket voucher baru harus sama dengan voucher lama.',
            default => null,
        };
        if ($error) {
            return view('portal.voucher', ['voucher' => null, 'remaining' => null, 'extendError' => $error]);
        }

        DB::transaction(function () use ($old, $new, $vouchers) {
            $p = $old->profile;
            $new->update(['status' => 'active', 'used_at' => now(), 'expired_at' => now()->addSeconds(VoucherService::seconds((int) $p->validity_value, $p->validity_unit))]);
            // Dulu voucher hasil perpanjangan tidak pernah tercatat sebagai penjualan.
            if ($p->include_in_sales && ! SalesLog::where('voucher_id', $new->id)->exists()) {
                SalesLog::create(['voucher_id' => $new->id, 'voucher_username' => $new->username, 'profile_id' => $p->id, 'profile_name' => $p->name, 'router_id' => $new->router_id, 'price' => $p->price, 'sold_at' => now()]);
            }
            $vouchers->retire($old, 'expired');
        });

        return view('portal.voucher', ['voucher' => $new->fresh('profile'), 'remaining' => null, 'extended' => true]);
    }
}

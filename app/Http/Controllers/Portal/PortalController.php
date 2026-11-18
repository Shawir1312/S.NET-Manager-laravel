<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Services\GenieACS\GenieACSService;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Payment\PaymentGatewayService;
use App\Services\Pppoe\BillingService;
use App\Services\Pppoe\OntWifiService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class PortalController extends Controller
{
    private function customer(Request $request): PppoeCustomer
    {
        return $request->attributes->get('customer');
    }

    public function home(Request $request, BillingService $billing, PaymentGatewayService $gateways): View
    {
        $c = $this->customer($request);
        $device = $c->ontDevice();

        return view('portal.home', [
            'customer' => $c,
            'bills' => $billing->unpaidBills($c),
            'payments' => $c->payments()->where('midtrans_status', 'paid')->latest('id')->limit(6)->get(),
            'gateways' => $gateways->available(),
            'tripayChannels' => in_array('tripay', $gateways->available(), true) ? $gateways->tripayChannels() : [],
            'device' => $device,
            'online' => $device?->informedRecently(),
            'announcements' => Announcement::current()->where('maintenance_mode', false)->latest('id')->limit(3)->get(),
        ]);
    }

    public function wifi(Request $request, OntWifiService $wifi): RedirectResponse
    {
        $d = $request->validate(['ssid' => ['nullable', 'string', 'min:2', 'max:32'], 'password' => ['nullable', 'string', 'min:8', 'max:63']], [], ['ssid' => 'nama WiFi', 'password' => 'password WiFi']);
        if (blank($d['ssid'] ?? null) && blank($d['password'] ?? null)) {
            return back()->withErrors(['ssid' => 'Isi nama WiFi atau password baru.']);
        }
        try {
            $msg = $wifi->change($this->customer($request), $d['ssid'] ?? null, $d['password'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['ssid' => 'Gagal mengubah WiFi: '.($e instanceof RuntimeException ? $e->getMessage() : 'modem tidak dapat dihubungi.')]);
        }

        return back()->with('status', $msg);
    }

    public function reboot(Request $request): RedirectResponse
    {
        $device = $this->customer($request)->ontDevice();
        if (! $device || ! ProvisioningService::isProduction()) {
            return back()->withErrors(['reboot' => 'Reboot modem belum tersedia untuk akun Anda.']);
        }
        rescue(fn () => GenieACSService::forDevice($device)->createTask($device->genieacs_id, ['name' => 'reboot']), report: false);

        return back()->with('status', 'Perintah restart dikirim. Internet terputus 1-2 menit.');
    }

    /** Ganti password portal (dulu selalu gagal karena variabel form tidak dibaca). */
    public function password(Request $request): RedirectResponse
    {
        $c = $this->customer($request);
        $d = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed']]);
        if (! Hash::check($d['current_password'], (string) $c->portal_password)) {
            return back()->withErrors(['current_password' => 'Password lama salah.']);
        }
        $c->forceFill(['portal_password' => $d['password']])->save();

        return back()->with('status', 'Password portal berhasil diubah.');
    }

    /** Mulai bayar: nominal diambil dari tagihan di server, bukan dari browser. */
    public function pay(Request $request, PaymentGatewayService $gateways): JsonResponse
    {
        $d = $request->validate(['period' => ['required', 'date_format:Y-m'], 'gateway' => ['required', 'in:midtrans,tripay'], 'channel' => ['nullable', 'string', 'max:30', 'alpha_num']]);
        [$year, $month] = array_map('intval', explode('-', $d['period']));
        try {
            return response()->json($gateways->start($this->customer($request), $month, $year, $d['gateway'], $d['channel'] ?? 'QRIS'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function paymentStatus(Request $request, PaymentGatewayService $gateways): JsonResponse
    {
        $orderId = $request->validate(['order_id' => ['required', 'string', 'max:100']])['order_id'];
        $payment = PppoePayment::where('customer_id', $this->customer($request)->id)->where('midtrans_order_id', $orderId)->firstOrFail();

        return response()->json(['status' => $gateways->refreshStatus($payment)]);
    }

    /** Lalu lintas real-time interface PPPoE pelanggan (bit/detik). */
    public function traffic(Request $request, MikrotikConnector $mikrotik): JsonResponse
    {
        $c = $this->customer($request);
        $r = rescue(fn () => $mikrotik->with($c->router, fn (MikrotikConnection $api) => $api->comm('/interface/monitor-traffic', ['interface' => "<pppoe-{$c->pppoe_username}>", 'once' => '']))[0] ?? null, null, report: false);

        return response()->json(['online' => $r !== null, 'download' => (int) ($r['tx-bits-per-second'] ?? 0), 'upload' => (int) ($r['rx-bits-per-second'] ?? 0)]);
    }
}

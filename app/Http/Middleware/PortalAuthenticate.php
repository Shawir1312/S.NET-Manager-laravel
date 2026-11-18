<?php

namespace App\Http\Middleware;

use App\Models\PppoeCustomer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sesi portal pelanggan (terpisah dari login admin). */
class PortalAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = PppoeCustomer::find($request->session()->get('portal_customer_id'));
        if (! $customer || ! in_array($customer->status, ['active', 'isolated'], true)) {
            $request->session()->forget('portal_customer_id');

            return redirect()->route('portal.login')->withErrors($customer ? ['username' => 'Akun Anda tidak aktif. Hubungi admin.'] : []);
        }
        $request->attributes->set('customer', $customer);

        return $next($request);
    }
}

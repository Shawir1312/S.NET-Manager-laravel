<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Halaman voucher publik ditanam (iframe) di halaman login hotspot MikroTik.
        if (! $request->routeIs('voucher.check', 'voucher.extend')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // Kamera hanya diizinkan (untuk domain sendiri) di halaman yang punya scan SN ONT; halaman lain tetap diblokir.
        $camera = $request->routeIs('ont-hotspot.index', 'field.hotspot', 'field.customers.create', 'customers.create', 'customers.edit') ? 'camera=(self)' : 'camera=()';
        $response->headers->set('Permissions-Policy', "{$camera}, microphone=(), geolocation=()");

        return $response;
    }
}

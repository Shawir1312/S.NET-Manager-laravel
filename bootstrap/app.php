<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SelectGenieServer;
use App\Services\GenieACS\Exceptions\DeviceNotFoundException;
use App\Services\GenieACS\Exceptions\GenieACSAuthException;
use App\Services\GenieACS\Exceptions\GenieACSException;
use App\Services\GenieACS\Exceptions\GenieACSUnreachableException;
use App\Services\Provisioning\ProvisioningException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API dipanggil dari halaman yang sama (fetch + cookie sesi + CSRF) lewat Sanctum.
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => EnsureUserIsActive::class,
            'genie.server' => SelectGenieServer::class,
        ]);

        $middleware->append(SecurityHeaders::class);
        // Webhook dari payment gateway & wa-service diverifikasi dengan signature/IP, bukan CSRF.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Error GenieACS: pesan ramah teknisi, tanpa stack trace / secret.
        $exceptions->render(function (GenieACSException $e, Request $request) {
            $status = match (true) {
                $e instanceof DeviceNotFoundException => 404,
                $e instanceof GenieACSUnreachableException, $e instanceof GenieACSAuthException => 503,
                default => 502,
            };

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $status);
            }

            return back()->withErrors(['genieacs' => $e->getMessage()]);
        });
        $exceptions->render(function (ProvisioningException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus);
            }

            return back()->withErrors(['provisioning' => $e->getMessage()]);
        });
        $exceptions->dontReport([DeviceNotFoundException::class, ProvisioningException::class]);

        // Jangan pernah membocorkan password/secret ke log maupun halaman error.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'pppoe_password', 'wifi_password', 'genieacs_password',
            'portal_password', 'ont_wifi_pass', 'api_password', 'api_token', 'radius_secret', 'midtrans_server_key', 'tripay_api_key', 'tripay_private_key']);
    })->create();

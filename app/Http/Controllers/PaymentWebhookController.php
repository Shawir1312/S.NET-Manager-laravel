<?php

namespace App\Http\Controllers;

use App\Services\Payment\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Callback Tripay & Midtrans (satu URL untuk keduanya, seperti portal/payment_webhook.php lama). */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGatewayService $gateways): JsonResponse
    {
        $ok = $request->hasHeader('X-Callback-Signature')
            ? $gateways->handleTripay($request->getContent(), (string) $request->header('X-Callback-Signature'), (string) $request->header('X-Callback-Event', 'payment_status'))
            : $gateways->handleMidtrans($request->json()->all() ?: $request->all());

        return response()->json(['success' => $ok], $ok ? 200 : 403);
    }
}

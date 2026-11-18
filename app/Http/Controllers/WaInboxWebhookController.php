<?php

namespace App\Http\Controllers;

use App\Models\PppoeCustomer;
use App\Models\WaInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/** Pesan masuk dari wa-service. Hanya menerima dari mesin lokal / jaringan privat (sama seperti dulu). */
class WaInboxWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(IpUtils::checkIp((string) $request->ip(), ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']), 403);
        $d = $request->validate(['sender_phone' => ['required', 'string', 'max:40'], 'is_group' => ['nullable']]);
        if ($request->boolean('is_group')) {
            return response()->json(['success' => true, 'skipped' => 'group']);
        }

        $phone = preg_replace('/\D/', '', $d['sender_phone']);
        $out = ($request->input('direction') ?? ($request->boolean('from_me') ? 'out' : 'in')) === 'out';
        $tail = substr($phone, -9);

        WaInbox::create([
            'message_id' => (string) $request->input('message_id', ''),
            'sender_phone' => $phone,
            'sender_name' => mb_substr((string) $request->input('sender_name', ''), 0, 150),
            'message_text' => (string) $request->input('text', ''),
            'message_type' => (string) $request->input('message_type', 'text'),
            'direction' => $out ? 'out' : 'in',
            'is_read' => $out,
            'customer_id' => $tail !== '' ? PppoeCustomer::where('phone', 'like', "%{$tail}")->value('id') : null,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}

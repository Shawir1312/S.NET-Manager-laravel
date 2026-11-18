<?php

namespace App\Http\Controllers;

use App\Models\PppoeCustomer;
use App\Models\WaInbox;
use App\Models\WaLog;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * Jembatan AJAX halaman WhatsApp (port dari ajax/wa_qr.php): QR, pairing, status, live chat,
 * grup & konfigurasi forward. Engine wa-service selalu dipanggil di 127.0.0.1 (port dari konfigurasi).
 */
class WhatsAppAjaxController extends Controller
{
    private const POST_ACTIONS = ['pairing_code', 'logout', 'restart', 'reset', 'send_chat', 'mark_read', 'reply', 'repair_session', 'save_config_gateway'];

    public function __invoke(Request $request, WhatsAppGateway $gateway): JsonResponse
    {
        Gate::authorize('manage-settings');
        $action = (string) $request->query('action', 'status');
        abort_if(in_array($action, self::POST_ACTIONS, true) && ! $request->isMethod('post'), 405);

        $port = (int) (parse_url((string) $gateway->config()->api_url, PHP_URL_PORT) ?: 3000);
        $node = "http://127.0.0.1:{$port}";

        return response()->json(match ($action) {
            'diagnose' => $this->diagnose($node, $port),
            'status' => $this->call($node, '/api/status', $port),
            'qr' => $this->call($node, '/api/qr'.($request->boolean('force') ? '?force=1' : ''), $port),
            'pairing_code' => filled($request->input('phone')) ? $this->call($node, '/api/pairing-code', $port, 'POST', ['phone' => (string) $request->input('phone')], 25) : ['success' => false, 'message' => 'Nomor WhatsApp wajib diisi'],
            'logout', 'restart', 'reset' => $this->call($node, '/api/'.$action, $port, 'POST'),
            'chats' => $this->chats($node, $port),
            'chat_messages' => $this->messages($node, $port, preg_replace('/\D/', '', (string) $request->query('phone'))),
            'send_chat' => $this->sendChat($node, $port, preg_replace('/\D/', '', (string) $request->input('phone')), trim((string) $request->input('message'))),
            'mark_read' => $this->markRead((string) $request->input('ids', $request->query('ids', ''))),
            'unread_count' => ['success' => true, 'unread' => WaInbox::where('is_read', false)->where('direction', 'in')->where('is_group', false)->count()],
            'reply' => $this->reply($node, $port, $request),
            'repair_session' => filled($p = preg_replace('/\D/', '', (string) $request->input('phone'))) ? $this->call($node, '/api/chat/repair-session', $port, 'POST', ['phone' => $p]) : ['success' => false, 'message' => 'Nomor WhatsApp wajib diisi'],
            'groups' => $this->call($node, '/api/groups', $port),
            'get_config' => $this->call($node, '/api/config', $port),
            'save_config_gateway' => $this->call($node, '/api/config', $port, 'POST', [
                'forward_group_jid' => (string) $request->input('forward_group_jid', ''),
                'forward_enabled' => $request->boolean('forward_enabled'),
                'webhook_url' => (string) $request->input('webhook_url', route('webhooks.wa-inbox')),
                'webhook_enabled' => $request->boolean('webhook_enabled', true),
            ]),
            default => ['error' => 'Invalid action'],
        });
    }

    /** @return array<string, mixed> */
    private function call(string $node, string $path, int $port, string $method = 'GET', array $data = [], int $timeout = 15): array
    {
        try {
            $res = Http::timeout($timeout)->connectTimeout(4)->send($method, $node.$path, $method === 'POST' ? ['json' => $data] : []);

            return $res->json() ?? ['raw' => $res->body(), 'status' => 'unknown', 'http_code' => $res->status()];
        } catch (ConnectionException $e) {
            return ['success' => false, 'status' => 'offline', 'http_code' => 0, 'curl_error' => $e->getMessage(), 'port' => $port,
                'message' => "Layanan background WhatsApp (Port {$port}) belum merespons. Pastikan service aktif: sudo systemctl status snet-wa"];
        }
    }

    /** @return array<string, mixed> */
    private function diagnose(string $node, int $port): array
    {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        if (is_resource($fp)) {
            fclose($fp);
        }

        return ['success' => true, 'host' => '127.0.0.1', 'port' => $port, 'port_open' => $fp !== false,
            'node_url' => $node, 'status_response' => $this->call($node, '/api/status', $port), 'server_time' => now()->format('Y-m-d H:i:s')];
    }

    /** @return list<string> 62xxx & 0xxx */
    private function variants(string $phone): array
    {
        return array_values(array_unique(array_filter([$phone,
            str_starts_with($phone, '62') ? '0'.substr($phone, 2) : null,
            str_starts_with($phone, '0') ? '62'.substr($phone, 1) : null])));
    }

    private function customerFor(string $phone): ?PppoeCustomer
    {
        foreach ($this->variants($phone) as $v) {
            $tail = substr($v, -9);
            $found = PppoeCustomer::where('phone', 'like', "%{$tail}")->first(['id', 'full_name', 'pppoe_username', 'profile', 'monthly_price', 'due_day', 'status']);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function chats(string $node, int $port): array
    {
        $nodeChats = $this->call($node, '/api/chats', $port);
        $list = [];
        foreach (($nodeChats['success'] ?? false) ? ($nodeChats['chats'] ?? []) : [] as $c) {
            $ph = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
            if ($ph !== '') {
                $list[$ph] = ['phone' => $ph, 'name' => $c['name'] ?? '+'.$ph, 'unread_count' => (int) ($c['unread_count'] ?? 0),
                    'last_message' => $c['last_message'] ?? '', 'last_time' => $c['last_time'] ?? now()->format('Y-m-d H:i:s'), 'total_messages' => (int) ($c['total_messages'] ?? 0)];
            }
        }

        $db = WaInbox::where('is_group', false)->selectRaw("sender_phone, max(id) as max_id, sum(case when is_read = 0 and direction = 'in' then 1 else 0 end) as db_unread, count(*) as total_db")
            ->groupBy('sender_phone')->get();
        $last = WaInbox::whereIn('id', $db->pluck('max_id'))->get()->keyBy('id');
        foreach ($db as $d) {
            $ph = preg_replace('/\D/', '', $d->sender_phone);
            $m = $last[$d->max_id];
            if (isset($list[$ph])) {
                $list[$ph]['unread_count'] = max($list[$ph]['unread_count'], (int) $d->db_unread);
            } else {
                $list[$ph] = ['phone' => $ph, 'name' => $m->sender_name ?: '+'.$ph, 'unread_count' => (int) $d->db_unread,
                    'last_message' => (string) $m->message_text, 'last_time' => $m->created_at?->format('Y-m-d H:i:s'), 'total_messages' => (int) $d->total_db];
            }
        }

        foreach ($list as &$chat) {
            $c = $this->customerFor($chat['phone']);
            $chat['customer'] = $c ? ['id' => $c->id, 'name' => $c->full_name, 'username' => $c->pppoe_username, 'profile' => $c->profile,
                'price' => (float) $c->monthly_price, 'due_day' => (int) $c->due_day, 'status' => $c->status] : null;
            if ($c) {
                $chat['name'] = $c->full_name;
            }
        }
        $list = array_values($list);
        usort($list, fn ($a, $b) => strtotime((string) $b['last_time']) <=> strtotime((string) $a['last_time']));

        return ['success' => true, 'gateway_status' => $nodeChats['status'] ?? 'unknown', 'connected_user' => $nodeChats['user'] ?? null,
            'total_unread' => array_sum(array_column($list, 'unread_count')), 'chats' => $list];
    }

    /** @return array<string, mixed> */
    private function messages(string $node, int $port, string $phone): array
    {
        if ($phone === '') {
            return ['success' => false, 'message' => 'Nomor WhatsApp tidak valid'];
        }
        $all = [];
        $seen = [];
        $nodeRes = $this->call($node, '/api/chat/'.$phone, $port);
        foreach (($nodeRes['success'] ?? false) ? ($nodeRes['messages'] ?? []) : [] as $m) {
            $seen[$m['id'] ?? ''] = true;
            $all[] = ['id' => $m['id'] ?? '', 'direction' => $m['direction'] ?? 'in', 'text' => $m['text'] ?? '', 'type' => $m['type'] ?? 'text',
                'timestamp' => $m['timestamp'] ?? now()->format('Y-m-d H:i:s'), 'status' => $m['status'] ?? 'delivered'];
        }
        $variants = $this->variants($phone);
        foreach (WaInbox::where('is_group', false)->whereIn('sender_phone', $variants)->orderBy('id')->get() as $dm) {
            $id = $dm->message_id ?: 'db_'.$dm->id;
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $all[] = ['id' => $id, 'direction' => $dm->direction, 'text' => (string) $dm->message_text, 'type' => $dm->message_type,
                    'timestamp' => $dm->created_at?->format('Y-m-d H:i:s'), 'status' => 'delivered'];
            }
        }
        WaInbox::whereIn('sender_phone', $variants)->where('is_read', false)->update(['is_read' => true]);
        $this->call($node, '/api/chat/read', $port, 'POST', ['phone' => $phone], 2);
        usort($all, fn ($a, $b) => strtotime((string) $a['timestamp']) <=> strtotime((string) $b['timestamp']));
        $c = $this->customerFor($phone);

        return ['success' => true, 'phone' => $phone, 'customer' => $c?->only(['id', 'full_name', 'pppoe_username', 'profile', 'monthly_price', 'due_day', 'status']), 'messages' => $all];
    }

    /** @return array<string, mixed> */
    private function sendChat(string $node, int $port, string $phone, string $message): array
    {
        if ($phone === '' || $message === '') {
            return ['success' => false, 'message' => 'Nomor dan pesan wajib diisi.'];
        }
        $resp = $this->call($node, '/api/chat/send', $port, 'POST', ['phone' => $phone, 'message' => $message]);
        if ($resp['success'] ?? false) {
            WaInbox::create(['message_id' => $resp['message_id'] ?? 'out_'.time(), 'sender_phone' => $phone, 'sender_name' => 'S.NET Admin',
                'message_text' => $message, 'message_type' => 'text', 'direction' => 'out', 'is_read' => true, 'created_at' => now()]);
            WaLog::create(['phone' => $phone, 'recipient_name' => 'Pelanggan', 'message_type' => 'waweb_chat', 'message_text' => $message,
                'status' => 'success', 'response_payload' => json_encode($resp), 'created_at' => now()]);
        }

        return $resp;
    }

    /** @return array<string, mixed> */
    private function markRead(string $ids): array
    {
        if ($ids === 'all') {
            WaInbox::where('is_read', false)->update(['is_read' => true]);
        } elseif ($ids !== '') {
            WaInbox::whereIn('id', array_map('intval', explode(',', $ids)))->update(['is_read' => true]);
        }

        return ['success' => true];
    }

    /** @return array<string, mixed> */
    private function reply(string $node, int $port, Request $request): array
    {
        $phone = trim((string) $request->input('phone'));
        $message = trim((string) $request->input('message'));
        if ($phone === '' || $message === '') {
            return ['success' => false, 'message' => 'Nomor dan pesan wajib diisi.'];
        }
        $resp = $this->call($node, '/api/reply', $port, 'POST', ['phone' => $phone, 'message' => $message]);
        if (($resp['success'] ?? false) && $request->integer('inbox_id') > 0) {
            WaInbox::whereKey($request->integer('inbox_id'))->update(['replied_at' => now(), 'reply_text' => $message, 'is_read' => true]);
        }

        return $resp;
    }
}

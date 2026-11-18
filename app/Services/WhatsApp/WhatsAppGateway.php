<?php

namespace App\Services\WhatsApp;

use App\Models\WaConfig;
use App\Models\WaInbox;
use App\Models\WaLog;
use App\Models\WaTemplate;
use App\Services\Settings\SnetSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Kirim WhatsApp lewat wa-service (Baileys, self-hosted), Fonnte, Ultramsg, Green API
 * atau API generik. Port dari include/WhatsAppGateway.php lama.
 */
class WhatsAppGateway
{
    public function __construct(private readonly SnetSettings $settings) {}

    public static function normalizePhone(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone) ?? '';

        return match (true) {
            str_starts_with($clean, '08') => '62'.substr($clean, 1),
            str_starts_with($clean, '8') => '62'.$clean,
            default => $clean,
        };
    }

    public function config(): WaConfig
    {
        return WaConfig::first()
            ?? new WaConfig(['provider' => 'waweb', 'api_url' => 'http://127.0.0.1:3000/api/send', 'api_token' => '', 'device_id' => '']);
    }

    /** @return array{name: string, message: string}|null */
    public function template(string $code): ?array
    {
        $row = WaTemplate::where('code', $code)->where('is_active', true)->first();

        return $row ? ['name' => $row->name, 'message' => $row->message] : (DefaultTemplates::ALL[$code] ?? null);
    }

    /**
     * Isi placeholder {nama}, {tagihan}, dst.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string
    {
        $money = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $company = $data['company_name'] ?? $this->settings->get('pppoe', 'company_name', config('app.name'));
        $map = [
            '{nama}' => $data['full_name'] ?? 'Pelanggan',
            '{username}' => $data['pppoe_username'] ?? '',
            '{tagihan}' => $money($data['monthly_price'] ?? $data['amount'] ?? 0),
            '{jatuh_tempo}' => isset($data['due_day']) ? 'Tanggal '.$data['due_day'] : ($data['due_date'] ?? now()->format('d M Y')),
            '{bulan}' => $data['month_name'] ?? now()->translatedFormat('F Y'),
            '{link_portal}' => $data['link_portal'] ?? route('portal.login'),
            '{link_receipt}' => $data['link_receipt'] ?? '',
            '{cs_phone}' => $data['cs_phone'] ?? $this->settings->get('pppoe', 'company_phone', ''),
            '{no_invoice}' => $data['no_invoice'] ?? '',
            '{waktu_bayar}' => $data['waktu_bayar'] ?? now()->format('d M Y, H:i').' WIB',
            '{nama_layanan}' => $company,
            '{company_name}' => $company,
            '{portal_username}' => $data['portal_username'] ?? ($data['pppoe_username'] ?? ''),
            '{portal_password}' => $data['portal_password'] ?? '',
            '{paket}' => $data['profile'] ?? '',
            '{diterima_oleh}' => $data['collector_name'] ?? 'Kasir / Petugas',
            '{petugas}' => $data['collector_name'] ?? 'Kasir / Petugas',
            '{metode}' => strtoupper($data['payment_method'] ?? 'CASH'),
            '{catatan}' => $data['notes'] ?? '',
        ];

        return strtr($template, array_map('strval', $map));
    }

    /**
     * Kirim pesan memakai template; null bila template tidak ada.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message: string}|null
     */
    public function sendTemplate(string $code, string $phone, array $data, ?int $customerId = null): ?array
    {
        $tpl = $this->template($code);

        return $tpl ? $this->send($phone, $this->render($tpl['message'], $data), $customerId, $code, (string) ($data['full_name'] ?? '')) : null;
    }

    /** @return array{success: bool, message: string} */
    public function send(string $phone, string $message, ?int $customerId = null, string $type = 'general', string $recipientName = '', ?string $imageUrl = null): array
    {
        $target = self::normalizePhone($phone);
        $cfg = $this->config();

        $error = match (true) {
            $target === '' => 'Nomor telepon tidak valid',
            $cfg->exists && ! $cfg->is_active => 'Notifikasi WhatsApp sedang dinonaktifkan di Pengaturan Gateway',
            $cfg->provider !== 'waweb' && blank($cfg->api_token) => 'WhatsApp Gateway belum dikonfigurasi (API Token kosong)',
            default => null,
        };

        if ($error === null) {
            $attempts = $cfg->provider === 'waweb' ? 2 : 1;
            for ($i = 1; $i <= $attempts; $i++) {
                [$ok, $error, $raw] = $this->dispatch($cfg, $target, $message, $imageUrl);
                if ($ok) {
                    $this->log($customerId, $target, $recipientName, $type, $message, 'success', $raw);

                    return ['success' => true, 'message' => 'Pesan WhatsApp berhasil dikirim.'];
                }
            }
        }

        $this->log($customerId, $target ?: $phone, $recipientName, $type, $message, 'failed', $error);

        return ['success' => false, 'message' => $error];
    }

    /** @return array{0: bool, 1: string, 2: string} */
    private function dispatch(WaConfig $cfg, string $phone, string $message, ?string $imageUrl): array
    {
        $url = $cfg->api_url ?: 'http://127.0.0.1:3000/api/send';
        $token = (string) $cfg->api_token;
        $http = Http::timeout(30)->connectTimeout(6);

        try {
            $response = match ($cfg->provider) {
                'waweb' => $http->post(str_contains($url, '/api/send') ? $url : rtrim($url, '/').'/api/send', array_filter(['phone' => $phone, 'message' => $message, 'image_url' => $imageUrl])),
                'fonnte' => $http->withHeaders(['Authorization' => $token])->asForm()->post($url, array_filter(['target' => $phone, 'message' => $message, 'countryCode' => '62', 'url' => $imageUrl])),
                'ultramsg' => $http->asForm()->post(rtrim($url, '/')."/{$cfg->device_id}/messages/".($imageUrl ? 'image' : 'chat'),
                    ['token' => $token, 'to' => '+'.$phone] + ($imageUrl ? ['image' => $imageUrl, 'caption' => $message] : ['body' => $message])),
                'greenapi' => $http->post(rtrim($url, '/')."/waInstance{$cfg->device_id}/sendMessage/{$token}", ['chatId' => $phone.'@c.us', 'message' => $message]),
                default => $http->withToken($token)->post($url, ['phone' => $phone, 'target' => $phone, 'to' => $phone, 'message' => $message, 'image_url' => $imageUrl]),
            };
        } catch (ConnectionException $e) {
            return [false, $e->getMessage(), ''];
        }

        return $this->interpret($response);
    }

    /** @return array{0: bool, 1: string, 2: string} */
    private function interpret(Response $response): array
    {
        if (! $response->successful()) {
            return [false, 'HTTP Error '.$response->status(), $response->body()];
        }
        $json = $response->json() ?? [];
        foreach (['status', 'sent', 'success'] as $flag) {
            if (($json[$flag] ?? null) === false) {
                return [false, $json['reason'] ?? $json['message'] ?? 'Gagal terkirim dari provider', $response->body()];
            }
        }

        return [true, '', $response->body()];
    }

    private function log(?int $customerId, string $phone, string $name, string $type, string $message, string $status, string $payload): void
    {
        WaLog::create([
            'customer_id' => $customerId, 'phone' => $phone, 'recipient_name' => $name, 'message_type' => $type,
            'message_text' => $message, 'status' => $status, 'response_payload' => $payload, 'created_at' => now(),
        ]);
        if ($status === 'success') {
            WaInbox::create([
                'sender_phone' => $phone, 'sender_name' => $name, 'message_text' => $message, 'message_type' => 'text',
                'direction' => 'out', 'is_read' => true, 'customer_id' => $customerId, 'created_at' => now(),
            ]);
        }
    }
}

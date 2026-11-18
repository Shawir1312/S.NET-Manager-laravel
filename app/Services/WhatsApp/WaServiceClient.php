<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Klien kecil untuk wa-service (Node/Baileys) yang tetap dipakai apa adanya. */
class WaServiceClient
{
    public function __construct(private readonly WhatsAppGateway $gateway) {}

    public function baseUrl(): string
    {
        $url = $this->gateway->config()->api_url ?: 'http://127.0.0.1:3000/api/send';

        return rtrim(preg_replace('#/api/.*$#', '', $url), '/');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, array $body = [], int $timeout = 10): array
    {
        try {
            $res = Http::timeout($timeout)->connectTimeout(3)->send($method, $this->baseUrl().$path, $body ? ['json' => $body] : []);

            return $res->json() ?? ['success' => $res->successful()];
        } catch (ConnectionException) {
            return ['success' => false, 'status' => 'offline', 'message' => 'wa-service tidak berjalan di '.$this->baseUrl().'. Jalankan setup_wa_service.sh di server.'];
        }
    }
}

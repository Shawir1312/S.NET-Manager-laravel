<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ConfigurationTemplate extends Model
{
    /** Kunci yang tidak boleh pernah tersimpan di template. */
    public const FORBIDDEN_KEYS = ['pppoe_password', 'password', 'wifi_password', 'ssid_password'];

    protected $fillable = ['name', 'service_type', 'description', 'payload', 'is_default', 'created_by'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'payload' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            $template->payload = self::stripSecrets($template->payload ?? []);
        });
    }

    /** Buang semua field password secara rekursif. */
    public static function stripSecrets(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = self::stripSecrets($value);
            }
        }

        return $payload;
    }

    /**
     * Keamanan WiFi hotspot dari template: [mode keamanan SSIDMapper, password].
     * keep = tidak diubah, open = tanpa password (None), password = WPA2 (11i) dengan password terenkripsi di payload.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function hotspotWifi(): array
    {
        $wifi = $this->payload['wifi'] ?? [];

        return match ($wifi['security'] ?? 'keep') {
            'open' => ['None', null],
            'password' => filled($wifi['key_enc'] ?? null) ? ['11i', rescue(fn () => Crypt::decryptString($wifi['key_enc']), null, false)] : [null, null],
            default => [null, null],
        };
    }

    /** Seksi hotspot template PPPoE sebagai template Bridge sementara (tidak disimpan), atau null bila tidak ada. */
    public function hotspotPart(): ?self
    {
        $hs = $this->service_type === ServiceType::Pppoe ? ($this->payload['hotspot'] ?? null) : null;
        if (! $hs) {
            return null;
        }
        $part = new self(['name' => $this->name.' — Hotspot', 'service_type' => ServiceType::Bridge, 'payload' => ['service_type' => ServiceType::Bridge->value] + $hs]);
        $part->id = $this->id;

        return $part;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

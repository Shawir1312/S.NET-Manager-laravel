<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenieAcsSetting extends Model
{
    protected $table = 'genieacs_settings';

    protected $fillable = [
        'name', 'nbi_url', 'cwmp_url', 'username', 'password', 'connect_timeout', 'request_timeout',
        'connection_request_timeout', 'verify_tls', 'is_active', 'last_test_status', 'last_test_message',
        'last_test_at', 'updated_by',
    ];

    /** Credential tidak pernah ikut ter-serialisasi ke JSON/frontend. */
    protected $hidden = ['username', 'password'];

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'verify_tls' => 'boolean',
            'is_active' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->latest('id')->first();
    }

    public function hasCredentials(): bool
    {
        return filled($this->getRawOriginal('username')) || filled($this->getRawOriginal('password'));
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

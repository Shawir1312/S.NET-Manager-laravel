<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsidConfiguration extends Model
{
    protected $fillable = [
        'configuration_job_id', 'ssid_index', 'ssid_name', 'password', 'security_mode', 'enabled', 'hidden', 'channel',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'enabled' => 'boolean',
            'hidden' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ConfigurationJob::class, 'configuration_job_id');
    }
}

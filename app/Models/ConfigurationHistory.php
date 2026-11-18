<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['configuration_job_id', 'event', 'status', 'message', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ConfigurationJob::class, 'configuration_job_id');
    }
}

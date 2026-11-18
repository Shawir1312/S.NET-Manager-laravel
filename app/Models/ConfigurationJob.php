<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ConfigurationJob extends Model
{
    protected $fillable = [
        'uuid', 'ont_device_id', 'user_id', 'configuration_template_id', 'service_type', 'request_payload', 'plan',
        'status', 'is_simulation', 'idempotency_key', 'genieacs_task_ids', 'error_code', 'error_message',
        'started_at', 'finished_at',
    ];

    protected $hidden = ['request_payload'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'request_payload' => 'encrypted:array',
            'plan' => 'array',
            'status' => JobStatus::class,
            'is_simulation' => 'boolean',
            'genieacs_task_ids' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(OntDevice::class, 'ont_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConfigurationTemplate::class, 'configuration_template_id');
    }

    public function wanConfigurations(): HasMany
    {
        return $this->hasMany(WanConfiguration::class);
    }

    public function ssidConfigurations(): HasMany
    {
        return $this->hasMany(SsidConfiguration::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(ConfigurationBackup::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ConfigurationHistory::class)->orderBy('created_at');
    }
}

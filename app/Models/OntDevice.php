<?php

namespace App\Models;

use App\Enums\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OntDevice extends Model
{
    protected $fillable = [
        'genie_acs_setting_id', 'genieacs_id', 'serial_number', 'manufacturer', 'vendor', 'oui', 'product_class', 'ont_model_id',
        'hardware_version', 'software_version', 'ip_address', 'last_inform_at', 'last_online_check_at',
        'last_online_result', 'parameters_snapshot', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'vendor' => Vendor::class,
            'last_inform_at' => 'datetime',
            'last_online_check_at' => 'datetime',
            'last_online_result' => 'boolean',
            'parameters_snapshot' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function genieAcsSetting(): BelongsTo
    {
        return $this->belongsTo(GenieAcsSetting::class);
    }

    public function ontModel(): BelongsTo
    {
        return $this->belongsTo(OntModel::class);
    }

    public function configurationJobs(): HasMany
    {
        return $this->hasMany(ConfigurationJob::class);
    }

    public function wanConfigurations(): HasMany
    {
        return $this->hasMany(WanConfiguration::class);
    }

    /**
     * Inform terakhir masih dalam ambang batas. Ini hanya indikasi dari data ACS,
     * BUKAN bukti online. Sebelum provisioning, status online selalu dicek ulang
     * lewat Connection Request (Phase 2).
     */
    public function informedRecently(?int $seconds = null): bool
    {
        $seconds ??= (int) config('ontconfig.online_threshold_seconds');

        return $this->last_inform_at !== null && $this->last_inform_at->gt(now()->subSeconds($seconds));
    }
}

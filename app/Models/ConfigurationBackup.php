<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationBackup extends Model
{
    protected $fillable = [
        'configuration_job_id', 'parameter_path', 'old_value', 'old_type', 'new_value', 'existed_before',
        'is_secret', 'applied', 'restore_status', 'restored_at',
    ];

    protected $hidden = ['old_value', 'new_value'];

    protected function casts(): array
    {
        return [
            // Semua nilai backup dienkripsi saat disimpan, tidak bergantung urutan pengisian is_secret.
            'old_value' => 'encrypted',
            'new_value' => 'encrypted',
            'existed_before' => 'boolean',
            'is_secret' => 'boolean',
            'applied' => 'boolean',
            'restored_at' => 'datetime',
        ];
    }

    /** Nilai untuk ditampilkan di UI (secret disamarkan). */
    public function displayValue(string $which): string
    {
        if ($this->is_secret) {
            return '••••••';
        }

        return (string) ($which === 'old' ? $this->old_value : $this->new_value);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ConfigurationJob::class, 'configuration_job_id');
    }
}

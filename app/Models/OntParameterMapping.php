<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OntParameterMapping extends Model
{
    protected $fillable = [
        'ont_model_id', 'key', 'path_template', 'value_type', 'value_map', 'verified', 'verification_level', 'verified_device', 'verified_at', 'source',
    ];

    protected function casts(): array
    {
        return [
            'value_map' => 'array',
            'verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public const LEVEL_RANK = ['path' => 1, 'manual' => 2, 'write' => 3];

    /** Naikkan tingkat verifikasi; tidak pernah menurunkan tingkat yang sudah lebih kuat. */
    public function promote(string $level, string $device, string $source): bool
    {
        // Mapping lama yang verified tanpa level dianggap "manual" (jangan ditimpa).
        $current = self::LEVEL_RANK[$this->verification_level ?? ($this->verified ? 'manual' : '')] ?? 0;
        if ($this->verified && $current >= self::LEVEL_RANK[$level]) {
            return false;
        }

        $this->forceFill([
            'verified' => true, 'verification_level' => $level, 'verified_device' => $device,
            'verified_at' => now(), 'source' => $source,
        ])->save();

        return true;
    }

    public function ontModel(): BelongsTo
    {
        return $this->belongsTo(OntModel::class);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified', true);
    }

    /**
     * Isi placeholder {nama} pada path_template.
     *
     * @param  array<string, int|string>  $vars
     */
    public function resolvePath(array $vars = []): string
    {
        $path = $this->path_template;
        foreach ($vars as $name => $value) {
            $path = str_replace('{'.$name.'}', (string) $value, $path);
        }

        if (preg_match('/\{[a-z_]+\}/i', $path)) {
            throw new \InvalidArgumentException("Placeholder pada path '{$this->key}' belum terisi: {$path}");
        }

        return $path;
    }
}

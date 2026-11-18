<?php

namespace App\Models;

use App\Enums\ModelSupportStatus;
use App\Enums\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OntModel extends Model
{
    protected $fillable = ['vendor', 'model_name', 'product_class', 'firmware_pattern', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'vendor' => Vendor::class,
            'status' => ModelSupportStatus::class,
        ];
    }

    public function capability(): HasOne
    {
        return $this->hasOne(OntCapability::class);
    }

    public function parameterMappings(): HasMany
    {
        return $this->hasMany(OntParameterMapping::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(OntDevice::class);
    }

    /** Apakah versi firmware cocok dengan pola model ini ("*" = semua). */
    public function matchesFirmware(?string $softwareVersion): bool
    {
        if ($this->firmware_pattern === '*' || $this->firmware_pattern === '') {
            return true;
        }

        return $softwareVersion !== null
            && @preg_match('/'.$this->firmware_pattern.'/', $softwareVersion) === 1;
    }
}

<?php

namespace App\Models;

use Database\Factories\OdpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ODP (Optical Distribution Point) di antara server/cabang dan pelanggan. */
class Odp extends Model
{
    /** @use HasFactory<OdpFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['router_id', 'name', 'latitude', 'longitude', 'capacity', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'capacity' => 'integer'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(PppoeCustomer::class);
    }
}

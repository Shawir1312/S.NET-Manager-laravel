<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotspotProfile extends Model
{
    protected $table = 'profiles';

    /** @var list<string> */
    protected $fillable = ['name', 'display_name', 'validity_value', 'validity_unit', 'duration_value', 'duration_unit', 'quota_mb', 'rate_up', 'rate_down', 'price', 'include_in_sales', 'reseller_percent', 'router_id', 'description', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'reseller_percent' => 'decimal:2', 'include_in_sales' => 'boolean', 'is_active' => 'boolean'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'profile_id');
    }
}

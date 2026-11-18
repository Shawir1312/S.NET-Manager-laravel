<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $table = 'vouchers';

    /** @var list<string> */
    protected $fillable = ['username', 'password', 'profile_id', 'router_id', 'batch_id', 'status', 'used_at', 'expired_at', 'generated_by', 'synced_to_master'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['used_at' => 'datetime', 'expired_at' => 'datetime', 'synced_to_master' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'profile_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}

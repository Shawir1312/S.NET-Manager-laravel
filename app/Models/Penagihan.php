<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Penagihan extends Model
{
    protected $table = 'penagihan';

    /** @var list<string> */
    protected $fillable = ['router_id', 'profile_id', 'total_pendapatan', 'bagian_reseller', 'pendapatan_bersih', 'estimasi_voucher', 'voucher_aktual', 'status_kecocokan', 'catatan', 'ditagih_oleh', 'tanggal'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tanggal' => 'date', 'total_pendapatan' => 'decimal:2', 'bagian_reseller' => 'decimal:2', 'pendapatan_bersih' => 'decimal:2'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'profile_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLog extends Model
{
    protected $table = 'sales_log';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['voucher_id', 'voucher_username', 'profile_id', 'profile_name', 'router_id', 'sold_by', 'price', 'sold_at', 'synced_to_master'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'sold_at' => 'datetime', 'synced_to_master' => 'boolean'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WgLog extends Model
{
    protected $table = 'wg_logs';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['event', 'router_id', 'router_name', 'details', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}

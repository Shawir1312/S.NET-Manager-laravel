<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedundantLog extends Model
{
    protected $table = 'redundant_logs';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['node_code', 'direction', 'status', 'summary', 'details', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}

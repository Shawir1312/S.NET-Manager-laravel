<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedundantNode extends Model
{
    protected $table = 'redundant_nodes';

    /** @var list<string> */
    protected $fillable = ['node_name', 'node_code', 'router_id', 'node_ip', 'api_key', 'status', 'last_sync_at', 'last_sync_status', 'total_vouchers_synced'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_sync_at' => 'datetime'];
    }

    /** @var list<string> */
    protected $hidden = ['api_key'];
}

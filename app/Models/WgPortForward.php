<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WgPortForward extends Model
{
    protected $table = 'wg_port_forwards';

    /** @var list<string> */
    protected $fillable = ['router_id', 'public_port', 'target_port', 'target_ip', 'protocol'];

    public function router(): BelongsTo
    {
        return $this->belongsTo(WgRouter::class, 'router_id');
    }
}

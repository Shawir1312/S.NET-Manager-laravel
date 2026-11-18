<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthSnapshot extends Model
{
    protected $table = 'pppoe_bandwidth_snapshots';

    /** @var list<string> */
    protected $fillable = ['router_id', 'username', 'month_year', 'committed_dl', 'committed_ul', 'last_if_tx', 'last_if_rx'];
}

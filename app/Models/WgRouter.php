<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WgRouter extends Model
{
    protected $table = 'wg_routers';

    /** @var list<string> */
    protected $fillable = ['name', 'location', 'public_key', 'private_key', 'tunnel_ip', 'listen_port', 'lan_subnets', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['private_key' => 'encrypted'];
    }

    /** @var list<string> */
    protected $hidden = ['private_key'];

    public function portForwards(): HasMany
    {
        return $this->hasMany(WgPortForward::class, 'router_id');
    }
}

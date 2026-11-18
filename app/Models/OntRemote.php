<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OntRemote extends Model
{
    protected $table = 'ont_remotes';

    /** @var list<string> */
    protected $fillable = ['ont_sn', 'ont_name', 'ont_ip', 'target_port', 'public_port', 'expires_at', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'is_active' => 'boolean'];
    }
}

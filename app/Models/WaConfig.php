<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaConfig extends Model
{
    protected $table = 'wa_config';

    /** @var list<string> */
    protected $fillable = ['provider', 'api_url', 'api_token', 'device_id', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['api_token' => 'encrypted', 'is_active' => 'boolean'];
    }
}

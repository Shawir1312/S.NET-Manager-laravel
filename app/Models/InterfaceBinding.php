<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterfaceBinding extends Model
{
    protected $fillable = ['wan_configuration_id', 'interface_type', 'interface_index', 'interface_path'];

    public function wanConfiguration(): BelongsTo
    {
        return $this->belongsTo(WanConfiguration::class);
    }
}

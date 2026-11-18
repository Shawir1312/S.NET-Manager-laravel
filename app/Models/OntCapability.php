<?php

namespace App\Models;

use App\Enums\BindingMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OntCapability extends Model
{
    protected $fillable = [
        'ont_model_id', 'max_wan_ip', 'max_wan_ppp', 'lan_ports', 'ssid_count', 'has_5g',
        'supports_bridge', 'supports_pppoe', 'binding_method', 'vlan_min', 'vlan_max', 'extra',
    ];

    protected function casts(): array
    {
        return [
            'has_5g' => 'boolean',
            'supports_bridge' => 'boolean',
            'supports_pppoe' => 'boolean',
            'binding_method' => BindingMethod::class,
            'extra' => 'array',
        ];
    }

    public function ontModel(): BelongsTo
    {
        return $this->belongsTo(OntModel::class);
    }
}

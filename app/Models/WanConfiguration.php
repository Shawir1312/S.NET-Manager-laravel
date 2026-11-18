<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WanConfiguration extends Model
{
    protected $fillable = [
        'configuration_job_id', 'ont_device_id', 'wan_name', 'slot', 'instance_path', 'created_new', 'connection_type',
        'vlan_id', 'vlan_priority', 'service_list', 'nat_enabled', 'pppoe_username', 'pppoe_password',
    ];

    protected $hidden = ['pppoe_password'];

    protected function casts(): array
    {
        return [
            'created_new' => 'boolean',
            'nat_enabled' => 'boolean',
            'pppoe_password' => 'encrypted',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ConfigurationJob::class, 'configuration_job_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(OntDevice::class, 'ont_device_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(InterfaceBinding::class);
    }
}

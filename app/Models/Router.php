<?php

namespace App\Models;

use Database\Factories\RouterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    /** @use HasFactory<RouterFactory> */
    use HasFactory;

    protected $table = 'routers';

    /** @var list<string> */
    protected $fillable = ['name', 'ip_address', 'nas_id', 'nas_ip', 'api_user', 'api_password', 'api_port', 'radius_secret', 'location', 'status', 'genie_acs_setting_id', 'default_vlan', 'pppoe_template_id', 'hotspot_template_id', 'last_seen', 'latitude', 'longitude'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['api_password' => 'encrypted', 'last_seen' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    /** @var list<string> */
    protected $hidden = ['api_password', 'radius_secret'];

    public function odps(): HasMany
    {
        return $this->hasMany(Odp::class);
    }

    public function genieAcsSetting(): BelongsTo
    {
        return $this->belongsTo(GenieAcsSetting::class);
    }

    public function pppoeTemplate(): BelongsTo
    {
        return $this->belongsTo(ConfigurationTemplate::class, 'pppoe_template_id');
    }

    public function hotspotTemplate(): BelongsTo
    {
        return $this->belongsTo(ConfigurationTemplate::class, 'hotspot_template_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(PppoeCustomer::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

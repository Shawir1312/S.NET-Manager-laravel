<?php

namespace App\Models;

use Database\Factories\PppoeCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PppoeCustomer extends Model
{
    /** @use HasFactory<PppoeCustomerFactory> */
    use HasFactory;

    protected $table = 'pppoe_customers';

    /** @var list<string> */
    protected $fillable = ['router_id', 'pppoe_username', 'portal_username', 'portal_password', 'full_name', 'phone', 'address', 'profile', 'monthly_price', 'is_free', 'due_day', 'status', 'ont_sn', 'ont_vlan', 'ont_wifi_ssid', 'ont_wifi_pass', 'isolated_at', 'isolated_reason', 'last_paid_at', 'last_paid_amount', 'notes', 'created_by', 'odp_id', 'latitude', 'longitude'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['portal_password' => 'hashed', 'ont_wifi_pass' => 'encrypted', 'is_free' => 'boolean', 'isolated_at' => 'datetime', 'last_paid_at' => 'date', 'latitude' => 'float', 'longitude' => 'float'];
    }

    /** @var list<string> */
    protected $hidden = ['portal_password', 'ont_wifi_pass'];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PppoePayment::class, 'customer_id');
    }

    /** ONT pelanggan di cache mesin provisioning (dicocokkan lewat SN persis). */
    public function ontDevice(): ?OntDevice
    {
        return $this->ont_sn !== '' ? OntDevice::where('serial_number', $this->ont_sn)->first() : null;
    }
}

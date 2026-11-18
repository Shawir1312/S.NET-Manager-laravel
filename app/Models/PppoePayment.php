<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PppoePayment extends Model
{
    protected $table = 'pppoe_payments';

    /** @var list<string> */
    protected $fillable = ['customer_id', 'amount', 'payment_method', 'payment_gateway', 'midtrans_order_id', 'midtrans_tx_id', 'midtrans_status', 'checkout_url', 'pay_code', 'qr_url', 'period_month', 'period_year', 'paid_at', 'notes', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PppoeCustomer::class, 'customer_id');
    }
}

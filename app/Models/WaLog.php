<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaLog extends Model
{
    protected $table = 'wa_logs';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['customer_id', 'phone', 'recipient_name', 'message_type', 'message_text', 'status', 'response_payload', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}

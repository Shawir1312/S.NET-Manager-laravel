<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaInbox extends Model
{
    protected $table = 'wa_inbox';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['message_id', 'sender_phone', 'sender_name', 'message_text', 'message_type', 'direction', 'is_group', 'group_jid', 'group_name', 'is_read', 'replied_at', 'reply_text', 'customer_id', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_group' => 'boolean', 'is_read' => 'boolean', 'replied_at' => 'datetime', 'created_at' => 'datetime'];
    }
}

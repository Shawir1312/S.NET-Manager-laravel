<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $table = 'announcements';

    /** @var list<string> */
    protected $fillable = ['type', 'title', 'body', 'is_active', 'show_on_login', 'maintenance_mode', 'start_at', 'end_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_on_login' => 'boolean', 'maintenance_mode' => 'boolean', 'start_at' => 'datetime', 'end_at' => 'datetime'];
    }

    /** @param  Builder<self>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()));
    }
}

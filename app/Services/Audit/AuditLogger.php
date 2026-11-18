<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /**
     * Catat aksi. Semua isi $changes otomatis diredaksi sebelum disimpan.
     *
     * @param  array<string, mixed>  $changes
     */
    public function log(string $action, ?Model $subject = null, array $changes = [], ?int $userId = null): AuditLog
    {
        $request = $this->request ?? request();

        return AuditLog::create([
            'user_id' => $userId ?? $request?->user()?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'changes' => $changes === [] ? null : Redactor::redact($changes),
        ]);
    }
}

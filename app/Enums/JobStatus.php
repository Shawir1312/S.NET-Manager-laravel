<?php

namespace App\Enums;

enum JobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Verifying = 'verifying';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Processing => 'Processing',
            self::Verifying => 'Verifikasi',
            self::Success => 'Success',
            self::Partial => 'Sebagian',
            self::Failed => 'Failed',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failed => 'danger',
            self::Partial => 'warning',
            self::Processing, self::Verifying => 'info',
            self::Pending, self::Cancelled => 'secondary',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed, self::Cancelled], true);
    }
}

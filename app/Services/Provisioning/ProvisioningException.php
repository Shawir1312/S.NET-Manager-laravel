<?php

namespace App\Services\Provisioning;

use RuntimeException;

/** Pesan exception ini aman ditampilkan ke teknisi. */
class ProvisioningException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'PROVISIONING_FAILED', public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

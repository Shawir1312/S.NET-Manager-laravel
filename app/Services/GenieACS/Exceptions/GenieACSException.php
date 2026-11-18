<?php

namespace App\Services\GenieACS\Exceptions;

use RuntimeException;

/**
 * Pesan exception ini AMAN ditampilkan ke teknisi (tanpa secret / stack trace).
 */
class GenieACSException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'GENIEACS_ERROR', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

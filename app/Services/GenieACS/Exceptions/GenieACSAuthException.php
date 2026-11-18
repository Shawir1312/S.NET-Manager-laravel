<?php

namespace App\Services\GenieACS\Exceptions;

class GenieACSAuthException extends GenieACSException
{
    public function __construct(string $message = 'Autentikasi ke GenieACS NBI gagal. Periksa username/password API.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'GENIEACS_AUTH_FAILED', $previous);
    }
}

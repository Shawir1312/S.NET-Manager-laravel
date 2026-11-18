<?php

namespace App\Services\GenieACS\Exceptions;

class GenieACSUnreachableException extends GenieACSException
{
    public function __construct(string $message = 'GenieACS tidak dapat diakses. Periksa URL NBI, jaringan, dan firewall.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'GENIEACS_UNREACHABLE', $previous);
    }
}

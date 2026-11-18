<?php

namespace App\Services\GenieACS\Exceptions;

class DeviceNotFoundException extends GenieACSException
{
    public function __construct(string $message = 'Perangkat tidak ditemukan di GenieACS.')
    {
        parent::__construct($message, 'DEVICE_NOT_FOUND');
    }
}

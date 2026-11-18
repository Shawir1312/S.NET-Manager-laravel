<?php

namespace App\Services\ONT\Exceptions;

use RuntimeException;

class ParameterUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $key, string $model)
    {
        parent::__construct("Parameter \"{$key}\" belum dipetakan untuk model {$model}. Tambahkan lewat menu Device Models setelah dicek di ONT asli.");
    }
}

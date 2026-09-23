<?php

namespace App\Services\Erzap;

use RuntimeException;

class ErzapException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $notConfigured = false)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Exceptions;

use Exception;

class InsufficientCashException extends Exception
{
    public function __construct(string $required, string $available)
    {
        parent::__construct("Insufficient cash balance. Required: {$required}, Available: {$available}.");
    }
}

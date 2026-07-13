<?php

namespace App\Exceptions;

use Exception;

class InvalidTransitionException extends Exception
{
    public function __construct(string $fromStatus, string $toStatus)
    {
        parent::__construct("Invalid requisition transition from '{$fromStatus}' to '{$toStatus}'.");
    }
}

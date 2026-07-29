<?php

namespace App\Exceptions;

use Exception;

class InsufficientCashException extends Exception
{
    public function __construct(string $required, string $available, ?string $remedy = null)
    {
        $remedy ??= 'Amend the requisition down to available cash, or request additional funds.';

        parent::__construct(
            "Insufficient cash on hand. Required: {$required}, Available: {$available}. {$remedy}"
        );
    }
}

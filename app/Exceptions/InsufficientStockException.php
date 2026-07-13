<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(int $inventoryItemId, int $locationId, string $required, string $available)
    {
        parent::__construct(
            "Insufficient stock for item #{$inventoryItemId} at location #{$locationId}. Required: {$required}, Available: {$available}."
        );
    }
}

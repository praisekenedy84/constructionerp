<?php

namespace App\Exceptions;

use App\Models\BoqItem;
use Exception;

class BOQLimitExceededException extends Exception
{
    public function __construct(BoqItem $boqItem, string $requestedQty)
    {
        parent::__construct(
            "Requested quantity {$requestedQty} exceeds available BOQ quantity {$boqItem->available_qty} for item #{$boqItem->id}."
        );
    }
}

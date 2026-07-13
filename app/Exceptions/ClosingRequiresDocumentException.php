<?php

namespace App\Exceptions;

use Exception;

class ClosingRequiresDocumentException extends Exception
{
    public function __construct(int $requisitionId)
    {
        parent::__construct("Requisition #{$requisitionId} cannot be closed without at least one attachment.");
    }
}

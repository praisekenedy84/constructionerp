<?php

namespace App\Enums;

enum InventoryTransactionType: string
{
    case In = 'IN';
    case Out = 'OUT';
    case Transfer = 'TRANSFER';
    case Return = 'RETURN';
    case Adjustment = 'ADJUSTMENT';
    case Damage = 'DAMAGE';
}

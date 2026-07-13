<?php

namespace App\Enums;

enum GoodsReceiptCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';
    case Partial = 'partial';
}

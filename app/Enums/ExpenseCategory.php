<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';
}

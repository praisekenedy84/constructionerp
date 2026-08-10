<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Indirect => 'Indirect',
        };
    }

    /** Label used when classifying requisition categories. */
    public function requisitionCategoryLabel(): string
    {
        return match ($this) {
            self::Direct => 'Project — Direct expense',
            self::Indirect => 'Administrative — Indirect expense',
        };
    }
}


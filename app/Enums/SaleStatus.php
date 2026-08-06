<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Open = 'open';
    case Receivable = 'receivable';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Receivable => 'Receivable',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
        };
    }

    public function isCollectable(): bool
    {
        return in_array($this, [self::Receivable, self::PartiallyPaid], true);
    }
}

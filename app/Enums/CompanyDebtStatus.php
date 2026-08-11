<?php

namespace App\Enums;

enum CompanyDebtStatus: string
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Cleared = 'cleared';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::PartiallyPaid => 'Partially paid',
            self::Cleared => 'Cleared',
        };
    }

    public function isPayable(): bool
    {
        return in_array($this, [self::Open, self::PartiallyPaid], true);
    }
}

<?php

namespace App\Enums;

enum CompanyDebtType: string
{
    case Loan = 'loan';
    case CustomerAdvance = 'customer_advance';

    public function label(): string
    {
        return match ($this) {
            self::Loan => 'Loan',
            self::CustomerAdvance => 'Customer advance',
        };
    }
}

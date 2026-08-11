<?php

namespace App\Enums;

enum DepositSource: string
{
    case OwnerCapital = 'owner_capital';
    case Loan = 'loan';
    case CustomerAdvance = 'customer_advance';
    case OtherIncome = 'other_income';
    case RetentionRelease = 'retention_release';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OwnerCapital => 'Owner / capital',
            self::Loan => 'Loan',
            self::CustomerAdvance => 'Customer advance',
            self::OtherIncome => 'Other income',
            self::RetentionRelease => 'Retention release',
            self::Other => 'Other',
        };
    }

    public function createsDebt(): bool
    {
        return in_array($this, [self::Loan, self::CustomerAdvance], true);
    }

    public function toDebtType(): ?CompanyDebtType
    {
        return match ($this) {
            self::Loan => CompanyDebtType::Loan,
            self::CustomerAdvance => CompanyDebtType::CustomerAdvance,
            default => null,
        };
    }
}

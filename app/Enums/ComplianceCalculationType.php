<?php

namespace App\Enums;

enum ComplianceCalculationType: string
{
    case RatePercent = 'rate_percent';
    case FixedAmount = 'fixed_amount';

    public function label(): string
    {
        return match ($this) {
            self::RatePercent => 'Rate %',
            self::FixedAmount => 'Fixed amount',
        };
    }
}

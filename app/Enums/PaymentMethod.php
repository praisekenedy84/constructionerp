<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Mobile = 'mobile';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Mobile => 'Mobile money',
            self::Bank => 'Bank transfer',
        };
    }
}

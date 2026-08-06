<?php

namespace App\Enums;

enum InvoiceTaxMode: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';

    public function label(): string
    {
        return match ($this) {
            self::Exclusive => 'Tax exclusive',
            self::Inclusive => 'Tax inclusive',
        };
    }
}

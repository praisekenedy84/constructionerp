<?php

namespace App\Enums;

enum RequisitionAddressedTo: string
{
    case Finance = 'finance';
    case Storekeeper = 'storekeeper';

    public function label(): string
    {
        return match ($this) {
            self::Finance => 'Finance',
            self::Storekeeper => 'Storekeeper',
        };
    }
}

<?php

namespace App\Enums;

enum RequisitionResourceType: string
{
    case Materials = 'materials';
    case Cash = 'cash';
    case Equipment = 'equipment';
    case Labor = 'labor';
    case Fuel = 'fuel';
    case Transport = 'transport';
    case Services = 'services';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Materials => 'Materials',
            self::Cash => 'Cash',
            self::Equipment => 'Equipment',
            self::Labor => 'Labor',
            self::Fuel => 'Fuel',
            self::Transport => 'Transport',
            self::Services => 'Services',
            self::Other => 'Other',
        };
    }
}

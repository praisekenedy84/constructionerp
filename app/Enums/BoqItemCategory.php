<?php

namespace App\Enums;

enum BoqItemCategory: string
{
    case Materials = 'materials';
    case Labor = 'labor';
    case Equipment = 'equipment';
    case Fuel = 'fuel';
    case Transport = 'transport';
    case Accommodation = 'accommodation';
    case Subcontractors = 'subcontractors';
    case Administration = 'administration';
    case Contingencies = 'contingencies';
}

<?php

namespace App\Enums;

enum EquipmentStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case UnderMaintenance = 'under_maintenance';
    case Retired = 'retired';
}

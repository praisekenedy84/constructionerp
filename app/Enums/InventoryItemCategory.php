<?php

namespace App\Enums;

enum InventoryItemCategory: string
{
    case Materials = 'materials';
    case Tools = 'tools';
    case Fuel = 'fuel';
    case Consumables = 'consumables';
    case SpareParts = 'spare_parts';
}

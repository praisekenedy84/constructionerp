<?php

namespace App\Enums;

enum BudgetTransactionType: string
{
    case ApprovedRequisition = 'APPROVED_REQUISITION';
    case AmendedRequisition = 'AMENDED_REQUISITION';
    case CashAllocation = 'CASH_ALLOCATION';
    case Purchase = 'PURCHASE';
    case Payroll = 'PAYROLL';
    case EquipmentCost = 'EQUIPMENT_COST';
    case FuelCost = 'FUEL_COST';
    case DirectExpense = 'DIRECT_EXPENSE';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
}

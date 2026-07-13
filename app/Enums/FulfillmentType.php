<?php

namespace App\Enums;

enum FulfillmentType: string
{
    case CashDisbursement = 'cash_disbursement';
    case StockIssue = 'stock_issue';
    case DirectSupplierPayment = 'direct_supplier_payment';
}

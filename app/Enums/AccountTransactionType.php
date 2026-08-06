<?php

namespace App\Enums;

enum AccountTransactionType: string
{
    case Deposit = 'deposit';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Disbursement = 'disbursement';
    case OpeningBalance = 'opening_balance';
    case Adjustment = 'adjustment';
    case ReceivablePayment = 'receivable_payment';
}

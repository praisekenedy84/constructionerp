<?php

namespace App\Enums;

enum CashAllocationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
}

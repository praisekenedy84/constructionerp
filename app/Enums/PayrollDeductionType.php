<?php

namespace App\Enums;

enum PayrollDeductionType: string
{
    case Nssf = 'NSSF';
    case Wcf = 'WCF';
    case Sdl = 'SDL';
    case AdvanceRecovery = 'advance_recovery';
    case Other = 'other';
}

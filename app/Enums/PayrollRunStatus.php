<?php

namespace App\Enums;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Posted = 'posted';
}

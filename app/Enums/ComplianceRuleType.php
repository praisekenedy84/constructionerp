<?php

namespace App\Enums;

enum ComplianceRuleType: string
{
    case Other = 'other';
    case Retention = 'retention';
    case AdvanceRecovery = 'advance_recovery';
    case Wht = 'wht';
    case DefectLiability = 'defect_liability';
    case MaterialTest = 'material_test';
    case HivReport = 'hiv_report';
}

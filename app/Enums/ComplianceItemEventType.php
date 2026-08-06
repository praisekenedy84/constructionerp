<?php

namespace App\Enums;

enum ComplianceItemEventType: string
{
    case AttachedToContract = 'attached_to_contract';
    case MigratedToPhase = 'migrated_to_phase';
}

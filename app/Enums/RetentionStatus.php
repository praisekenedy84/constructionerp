<?php

namespace App\Enums;

enum RetentionStatus: string
{
    case None = 'none';
    case Held = 'held';
    case Released = 'released';
    case Forfeited = 'forfeited';
}

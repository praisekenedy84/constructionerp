<?php

namespace App\Enums;

enum PhaseStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case Unsatisfactory = 'unsatisfactory';
    case Closed = 'closed';
}

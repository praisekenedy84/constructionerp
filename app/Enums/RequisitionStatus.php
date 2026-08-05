<?php

namespace App\Enums;

enum RequisitionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Amended = 'amended';
    case Rejected = 'rejected';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}

<?php

namespace Add\Hr\Recruitment\Enums;

/**
 * A request to hire: decided through approvals, then filled or closed.
 */
enum RequisitionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Filled = 'filled';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}

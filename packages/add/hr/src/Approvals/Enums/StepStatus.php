<?php

namespace Add\Hr\Approvals\Enums;

enum StepStatus: string
{
    /** A later step, not reached yet. */
    case Waiting = 'waiting';

    /** The current step: its approver may decide. */
    case Pending = 'pending';

    case Approved = 'approved';
    case Rejected = 'rejected';

    /** The request was withdrawn, or decided before this step was reached. */
    case Cancelled = 'cancelled';
}

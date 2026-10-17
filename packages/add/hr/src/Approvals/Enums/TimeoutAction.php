<?php

namespace Add\Hr\Approvals\Enums;

enum TimeoutAction: string
{
    /** Hand the step to the approver's own manager (or to HR). */
    case Escalate = 'escalate';
    case Approve = 'approve';
    case Reject = 'reject';
}

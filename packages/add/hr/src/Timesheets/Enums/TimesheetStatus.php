<?php

namespace Add\Hr\Timesheets\Enums;

/**
 * draft → submitted → approved, or rejected back into the employee's hands
 * (editing a rejected week makes it a draft again).
 */
enum TimesheetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}

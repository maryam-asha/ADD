<?php

namespace Add\Hr\Payroll\Enums;

/**
 * open → calculated → reviewed → approved (payslips issued, immutable) →
 * paid. Posting to accounting is a separate mark (posted_at). A correction
 * after approval is an off-cycle run, never an edit.
 */
enum PayrollRunStatus: string
{
    case Open = 'open';
    case Calculated = 'calculated';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Paid = 'paid';
}

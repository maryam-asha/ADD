<?php

namespace Add\Hr\Payroll\Events;

use Add\Hr\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A run was approved: its payslips are final. Dispatched inside the
 * approval's transaction, so a listener's writes stand or fall with it.
 */
class PayrollRunApproved
{
    use Dispatchable;

    public function __construct(public readonly PayrollRun $run) {}
}

<?php

namespace Add\Hr\Payroll\Listeners;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Payroll\Models\Loan;

/**
 * A loan still awaiting approval when a termination is recorded is called
 * off: no later payroll would deduct it.
 */
class CancelLoansAfterTermination
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    public function handle(EmployeeTerminated $event): void
    {
        Loan::query()->where('employee_id', $event->employee->id)->where('status', LoanStatus::Pending->value)->with('approval')->get()
            ->each(function (Loan $loan) {
                if ($loan->approval?->isPending()) {
                    $this->approvals->cancel($loan->approval);
                }
                $loan->update(['status' => LoanStatus::Cancelled]);
                HrAudit::record('loan_cancelled_on_termination', $loan);
            });
    }
}

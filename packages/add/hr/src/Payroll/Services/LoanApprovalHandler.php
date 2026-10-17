<?php

namespace Add\Hr\Payroll\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Support\HrException;
use Illuminate\Database\Eloquent\Model;

class LoanApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly LoanService $loans) {}

    /**
     * @param  Loan  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== LoanStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }
    }

    /**
     * @param  Loan  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $this->loans->activate($subject);
    }

    /**
     * @param  Loan  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => LoanStatus::Rejected]);
    }

    /**
     * @param  Loan  $subject
     */
    public function summary(Model $subject): array
    {
        return [
            'loan_id' => $subject->id,
            'amount' => $subject->amount,
            'currency' => $subject->currency,
            'installments' => $subject->installments,
            'installment_amount' => $subject->installment_amount,
            'first_month' => sprintf('%04d-%02d', $subject->first_year, $subject->first_month),
            'reason' => $subject->reason,
        ];
    }
}

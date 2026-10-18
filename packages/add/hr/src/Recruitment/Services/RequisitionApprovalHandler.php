<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Support\HrException;
use Illuminate\Database\Eloquent\Model;

class RequisitionApprovalHandler implements ApprovalHandler
{
    /**
     * @param  Requisition  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== RequisitionStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }
    }

    /**
     * @param  Requisition  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => RequisitionStatus::Approved, 'approved_at' => now()]);
    }

    /**
     * @param  Requisition  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => RequisitionStatus::Rejected]);
    }

    /**
     * @param  Requisition  $subject
     */
    public function summary(Model $subject): array
    {
        return [
            'requisition_id' => $subject->id,
            'title' => $subject->title,
            'headcount' => $subject->headcount,
            'org_unit' => $subject->orgUnit?->name,
            'reason' => $subject->reason->value,
            'budget_min' => $subject->budget_min,
            'budget_max' => $subject->budget_max,
            'budget_currency' => $subject->budget_currency,
            'justification' => $subject->justification,
        ];
    }
}

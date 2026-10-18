<?php

namespace Add\Hr\Training\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Support\HrException;
use Add\Hr\Training\Enums\LearningRequestStatus;
use Add\Hr\Training\Models\LearningRequest;
use Illuminate\Database\Eloquent\Model;

class LearningApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly LearningBudget $budget) {}

    /**
     * @param  LearningRequest  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== LearningRequestStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }
        if ($final && $subject->employee->terminated_on !== null) {
            HrException::fail('hr::training.employee_leaving');
        }
        if ($final) {
            // An adjustment may have lowered the balance since it was asked.
            $this->budget->assertAvailable($subject->employee_id, $subject->year, $subject->amount, $subject->id);
        }
    }

    /**
     * @param  LearningRequest  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => LearningRequestStatus::Approved]);
        $this->budget->spend($subject);
    }

    /**
     * @param  LearningRequest  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => LearningRequestStatus::Rejected]);
    }

    /**
     * @param  LearningRequest  $subject
     */
    public function summary(Model $subject): array
    {
        return [
            'learning_request_id' => $subject->id,
            'title' => $subject->title,
            'provider' => $subject->provider,
            'starts_on' => $subject->starts_on?->toDateString(),
            'amount' => $subject->amount,
            'currency' => $subject->currency,
            'year' => $subject->year,
            'available' => $this->budget->available($subject->employee_id, $subject->year, $subject->id),
            'justification' => $subject->justification,
        ];
    }
}

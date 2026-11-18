<?php

namespace Add\Hr\Training\Listeners;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Training\Enums\LearningRequestStatus;
use Add\Hr\Training\Models\LearningRequest;

/**
 * A learning spend still awaiting approval when a termination is recorded
 * is called off.
 */
class CancelLearningAfterTermination
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    public function handle(EmployeeTerminated $event): void
    {
        LearningRequest::query()->where('employee_id', $event->employee->id)->where('status', LearningRequestStatus::Pending->value)->with('approvalRequest')->get()
            ->each(function (LearningRequest $request) {
                if ($request->approvalRequest?->isPending()) {
                    $this->approvals->cancel($request->approvalRequest);
                }
                $request->update(['status' => LearningRequestStatus::Cancelled]);
                HrAudit::record('learning_request_cancelled_on_termination', $request);
            });
    }
}

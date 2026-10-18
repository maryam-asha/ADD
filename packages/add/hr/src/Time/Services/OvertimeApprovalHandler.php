<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\OvertimeKind;
use Add\Hr\Time\Enums\OvertimeStatus;
use Add\Hr\Time\Models\OvertimeRequest;
use Illuminate\Database\Eloquent\Model;

class OvertimeApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly OvertimeService $overtime,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * @param  OvertimeRequest  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== OvertimeStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }

        $this->periods->assertOpen($subject->work_date);

        if ($subject->kind === OvertimeKind::Post) {
            $this->overtime->assertRecorded($subject->employee, $subject->work_date, $subject->minutes, $subject->id);
        }
    }

    /**
     * @param  OvertimeRequest  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => OvertimeStatus::Approved]);
    }

    /**
     * @param  OvertimeRequest  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => OvertimeStatus::Rejected]);
    }

    /**
     * @param  OvertimeRequest  $subject
     */
    public function summary(Model $subject): array
    {
        return [
            'overtime_request_id' => $subject->id,
            'work_date' => $subject->work_date->toDateString(),
            'minutes' => $subject->minutes,
            'kind' => $subject->kind->value,
            'reason' => $subject->reason,
        ];
    }
}

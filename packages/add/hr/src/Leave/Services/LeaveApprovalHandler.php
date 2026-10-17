<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Support\HrException;
use Add\Hr\Support\PlainNumber;
use Add\Hr\Time\Services\AttendanceProcessor;
use Add\Hr\Time\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Leave in the approval engine. Every step re-checks the balance and the
 * coverage — both may have moved since submission — so an approver learns
 * of a problem at their own step, not after the last one. The last step
 * books the days.
 */
class LeaveApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly LeaveLedger $ledger,
        private readonly CoverageChecker $coverage,
        private readonly AttendanceProcessor $attendance,
        private readonly ScheduleResolver $schedule,
    ) {}

    /**
     * @param  LeaveRequest  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== LeaveRequestStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }

        $type = $subject->leaveType;

        if ($type->tracks_balance) {
            $policy = $this->leave->policyOrFail($subject->employee, $type, CarbonImmutable::parse($subject->starts_on));
            $this->leave->assertAvailable($subject->employee, $type, (float) $subject->amount, $policy, $subject->id);
        }

        $this->coverage->assertCovered($subject);
    }

    /**
     * @param  LeaveRequest  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $subject->status = LeaveRequestStatus::Approved;
        $subject->save();

        if ($subject->leaveType->tracks_balance) {
            $this->ledger->consume($subject);
        }

        // Leave approved after the fact (sick leave, ...) or covering today:
        // those days are rebuilt as leave.
        $last = min($subject->ends_on, $this->schedule->today());

        for ($day = $subject->starts_on; $day->lte($last); $day = $day->addDay()) {
            $this->attendance->process($subject->employee, $day);
        }
    }

    /**
     * @param  LeaveRequest  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->status = LeaveRequestStatus::Rejected;
        $subject->save();
    }

    /**
     * @param  LeaveRequest  $subject
     */
    public function summary(Model $subject): array
    {
        $type = $subject->leaveType;

        return [
            'leave_request_id' => $subject->id,
            'leave_type' => ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'unit' => $type->unit->value],
            'starts_on' => $subject->starts_on->toDateString(),
            'ends_on' => $subject->ends_on->toDateString(),
            'half_day' => $subject->half_day?->value,
            'amount' => PlainNumber::of($subject->amount),
            'reason' => $subject->reason,
            'has_attachment' => $subject->document_id !== null,
        ];
    }
}

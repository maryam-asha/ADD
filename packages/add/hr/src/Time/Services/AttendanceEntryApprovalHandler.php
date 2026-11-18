<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\EntryStatus;
use Add\Hr\Time\Models\AttendanceEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's own times that no card scan confirmed, before their manager.
 */
class AttendanceEntryApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly AttendanceEntryService $entries,
        private readonly PeriodGuard $periods,
        private readonly ScheduleResolver $schedule,
    ) {}

    /**
     * @param  AttendanceEntry  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== EntryStatus::Pending) {
            HrException::fail('hr::approvals.not_pending');
        }

        $this->periods->assertOpen($subject->work_date);
    }

    /**
     * @param  AttendanceEntry  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $this->entries->applyApproved($subject);
    }

    /**
     * @param  AttendanceEntry  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => EntryStatus::Rejected]);
    }

    /**
     * @param  AttendanceEntry  $subject
     */
    public function summary(Model $subject): array
    {
        $timezone = $this->schedule->timezone();

        return [
            'attendance_entry_id' => $subject->id,
            'work_date' => $subject->work_date->toDateString(),
            'check_in' => $subject->check_in_at?->setTimezone($timezone)->format('H:i'),
            'check_out' => $subject->check_out_at?->setTimezone($timezone)->format('H:i'),
            'note' => $subject->note,
        ];
    }
}

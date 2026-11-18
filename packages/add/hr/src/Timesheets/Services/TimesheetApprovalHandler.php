<?php

namespace Add\Hr\Timesheets\Services;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Support\HrException;
use Add\Hr\Support\PlainNumber;
use Add\Hr\Timesheets\Enums\TimesheetStatus;
use Add\Hr\Timesheets\Models\Timesheet;
use Illuminate\Database\Eloquent\Model;

class TimesheetApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly TimesheetService $timesheets,
        private readonly TimesheetReconciliation $reconciliation,
    ) {}

    /**
     * @param  Timesheet  $subject
     */
    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        if ($subject->status !== TimesheetStatus::Submitted) {
            HrException::fail('hr::approvals.not_pending');
        }

        $this->timesheets->assertOpenWeek($subject);
    }

    /**
     * @param  Timesheet  $subject
     */
    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => TimesheetStatus::Approved]);
    }

    /**
     * @param  Timesheet  $subject
     */
    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        $subject->update(['status' => TimesheetStatus::Rejected]);
    }

    /**
     * @param  Timesheet  $subject
     */
    public function summary(Model $subject): array
    {
        $reconciliation = $this->reconciliation->for($subject->employee, $subject->week_start, $subject);

        return [
            'timesheet_id' => $subject->id,
            'week_start' => $subject->week_start->toDateString(),
            'total_hours' => $subject->total_hours,
            'attendance_hours' => PlainNumber::of($reconciliation['attendance_hours']),
            'difference_hours' => PlainNumber::of($reconciliation['difference_hours']),
        ];
    }
}

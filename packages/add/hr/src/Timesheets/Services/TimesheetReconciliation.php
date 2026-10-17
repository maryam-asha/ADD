<?php

namespace Add\Hr\Timesheets\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\PlainNumber;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Timesheets\Models\Timesheet;
use Carbon\CarbonImmutable;

/**
 * A week's timesheet hours beside the hours attendance recorded, day by
 * day. Shown to the employee and the approver; it decides nothing unless
 * a variance limit is set.
 */
class TimesheetReconciliation
{
    /**
     * @return array{days: list<array<string, mixed>>, timesheet_hours: float, attendance_hours: float, difference_hours: float}
     */
    public function for(Employee $employee, CarbonImmutable $weekStart, ?Timesheet $timesheet): array
    {
        $weekEnd = $weekStart->addDays(6);
        $logged = $timesheet === null ? collect() : $timesheet->entries()->get()
            ->groupBy(fn ($entry) => $entry->work_date->toDateString())
            ->map(fn ($entries) => (float) $entries->sum(fn ($entry) => (float) $entry->hours));
        $worked = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->mapWithKeys(fn (AttendanceDay $day) => [$day->work_date->toDateString() => round($day->worked_minutes / 60, 2)]);

        $days = [];
        $totals = [0.0, 0.0];

        for ($day = $weekStart; $day->lte($weekEnd); $day = $day->addDay()) {
            $date = $day->toDateString();
            $hours = (float) ($logged[$date] ?? 0);
            $attended = (float) ($worked[$date] ?? 0);
            $totals[0] += $hours;
            $totals[1] += $attended;

            $days[] = [
                'date' => $date,
                'timesheet_hours' => PlainNumber::of($hours),
                'attendance_hours' => PlainNumber::of($attended),
                'difference_hours' => PlainNumber::of($hours - $attended),
            ];
        }

        return [
            'days' => $days,
            'timesheet_hours' => round($totals[0], 2),
            'attendance_hours' => round($totals[1], 2),
            'difference_hours' => round($totals[0] - $totals[1], 2),
        ];
    }
}

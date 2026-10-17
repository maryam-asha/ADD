<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Decimal;
use Add\Hr\Time\Enums\DayStatus;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Services\ScheduleResolver;

/**
 * A deduction for days not paid: approved unpaid leave (its working days;
 * half days count a half) and days a reviewer resolved as absent, at the
 * daily rate (monthly pay on config.base_components / config.days_per_month).
 */
class UnpaidDaysCalculator implements ComponentCalculator
{
    public function __construct(private readonly ScheduleResolver $schedule) {}

    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $days = '0';

        $leave = LeaveRequest::query()
            ->where('employee_id', $context->employee->id)
            ->where('status', LeaveRequestStatus::Approved->value)
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', false)->where('unit', 'day'))
            ->overlapping($context->from, $context->to)
            ->get();

        foreach ($leave as $request) {
            $from = $request->starts_on->max($context->from);
            $to = $request->ends_on->min($context->to);

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                if ($this->schedule->planned($context->employee, $day)->isWorking()) {
                    $days = Decimal::add($days, $request->half_day === null ? '1' : '0.5');
                }
            }
        }

        $absent = AttendanceDay::query()
            ->where('employee_id', $context->employee->id)
            ->whereBetween('work_date', [$context->from->toDateString(), $context->to->toDateString()])
            ->where('status', DayStatus::Absent->value)
            ->count();
        $days = Decimal::add($days, (string) $absent);

        if (Decimal::cmp($days, '0') <= 0) {
            return [];
        }

        $daily = Decimal::div(
            $context->monthlyPay((array) $component->setting('base_components', ['basic'])),
            (string) $component->setting('days_per_month', 30),
        );

        return [new LineResult($component, $context->money(Decimal::mul($daily, $days)),
            basis: Decimal::trim($days).' days', quantity: Decimal::trim($days), rate: Decimal::round($daily, 4))];
    }
}

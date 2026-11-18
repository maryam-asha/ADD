<?php

namespace Add\Hr\Leave\Support;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Leave\Enums\GrantCycle;
use Add\Hr\Leave\Models\LeavePolicy;
use Carbon\CarbonImmutable;

/**
 * One entitlement period for an employee: a calendar year, or a year that
 * runs from a hire anniversary to the day before the next. Ledger rows
 * belong to a period by `year` (period_year), the year it starts in, so a
 * calendar cycle is exactly the bucket the ledger always used.
 */
final class LeaveCycle
{
    private function __construct(
        public readonly int $year,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * The kind of cycle a policy runs on. Only a yearly grant can follow the
     * hire anniversary; monthly accrual and manual-only policies count the
     * calendar year.
     */
    public static function kindOf(?LeavePolicy $policy): GrantCycle
    {
        return $policy?->accrual_method === AccrualMethod::AnnualGrant && $policy->grant_cycle === GrantCycle::HireAnniversary
            ? GrantCycle::HireAnniversary
            : GrantCycle::CalendarYear;
    }

    /**
     * The cycle of `$kind` that starts in `$year`.
     */
    public static function startingIn(GrantCycle $kind, Employee $employee, int $year): self
    {
        if ($kind === GrantCycle::CalendarYear) {
            $start = CarbonImmutable::create($year)->startOfYear();

            return new self($year, $start, $start->endOfYear()->startOfDay());
        }

        return new self($year, self::anniversary($employee, $year), self::anniversary($employee, $year + 1)->subDay());
    }

    /**
     * The cycle of `$kind` that `$day` falls in.
     */
    public static function containing(GrantCycle $kind, Employee $employee, CarbonImmutable $day): self
    {
        $day = $day->startOfDay();

        if ($kind === GrantCycle::CalendarYear) {
            return self::startingIn($kind, $employee, $day->year);
        }

        $year = self::anniversary($employee, $day->year)->gt($day) ? $day->year - 1 : $day->year;

        return self::startingIn($kind, $employee, $year);
    }

    /**
     * The hire anniversary in `$year`. Someone hired on 29 February has it on
     * the 28th in other years.
     */
    public static function anniversary(Employee $employee, int $year): CarbonImmutable
    {
        $hired = CarbonImmutable::parse($employee->hired_on);
        $day = min($hired->day, CarbonImmutable::create($year, $hired->month)->daysInMonth);

        return CarbonImmutable::create($year, $hired->month, $day)->startOfDay();
    }

    public function contains(CarbonImmutable $day): bool
    {
        $day = $day->startOfDay();

        return $day->gte($this->start) && $day->lte($this->end);
    }

    public function days(): int
    {
        return (int) abs($this->start->diffInDays($this->end)) + 1;
    }

    /**
     * The share of the cycle left from `$from` to its end, both counted.
     */
    public function shareFrom(CarbonImmutable $from): float
    {
        return ((int) abs($from->startOfDay()->diffInDays($this->end)) + 1) / $this->days();
    }
}

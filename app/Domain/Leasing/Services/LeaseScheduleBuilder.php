<?php

namespace App\Domain\Leasing\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A lease's billing periods, computed without the database. Period k starts
 * k × cycle months after the lease start — always from the start date,
 * never chained from the previous period, so a start on the 31st returns
 * to the 31st after February instead of staying on the 28th.
 */
final class LeaseScheduleBuilder
{
    private const CYCLES = [1, 3, 6];

    /** Monthly, quarterly, half-yearly, or one payment for the whole term, dividing it exactly. */
    public static function isValidCycle(int $durationMonths, int $cycleMonths): bool
    {
        return $durationMonths >= 1
            && $cycleMonths >= 1
            && (in_array($cycleMonths, self::CYCLES, true) || $cycleMonths === $durationMonths)
            && $durationMonths % $cycleMonths === 0;
    }

    /** The lease's last day (Y-m-d). */
    public function endsOn(string $startsOn, int $durationMonths): string
    {
        return $this->monthsAfter($startsOn, $durationMonths)->subDay()->toDateString();
    }

    /** @return list<array{sequence: int, starts_on: string, ends_on: string, amount: string}> */
    public function build(string $startsOn, int $durationMonths, int $cycleMonths, string $monthlyAmount): array
    {
        if (! self::isValidCycle($durationMonths, $cycleMonths)) {
            throw new InvalidArgumentException("A {$cycleMonths}-month cycle does not divide a {$durationMonths}-month lease.");
        }

        $amount = bcmul($monthlyAmount, (string) $cycleMonths, 2);
        $periods = [];

        for ($k = 0, $count = intdiv($durationMonths, $cycleMonths); $k < $count; $k++) {
            $periods[] = [
                'sequence' => $k + 1,
                'starts_on' => $this->monthsAfter($startsOn, $k * $cycleMonths)->toDateString(),
                'ends_on' => $this->monthsAfter($startsOn, ($k + 1) * $cycleMonths)->subDay()->toDateString(),
                'amount' => $amount,
            ];
        }

        return $periods;
    }

    private function monthsAfter(string $startsOn, int $months): CarbonImmutable
    {
        return CarbonImmutable::parse($startsOn)->startOfDay()->addMonthsNoOverflow($months);
    }
}

<?php

namespace Add\Hr\Reports\Services;

use Add\Hr\Core\Models\Assignment;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Headcount and turnover by month: employed on the first and last day,
 * hired and left (last working day) within it, and turnover as leavers
 * over the mean headcount. Plus where people work at the end of the range.
 */
class HeadcountReport
{
    /**
     * @return array{months: list<array<string, mixed>>, by_unit: list<array<string, mixed>>}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $first = $month;
            $last = $month->endOfMonth()->startOfDay();
            $opening = self::employedOn($first)->count();
            $closing = self::employedOn($last)->count();
            $leavers = Employee::query()->whereBetween('terminated_on', [$first->toDateString(), $last->toDateString()])->count();
            $mean = Decimal::div($opening + $closing, 2);

            $months[] = [
                'month' => $month->format('Y-m'),
                'opening' => $opening,
                'hires' => Employee::query()->whereBetween('hired_on', [$first->toDateString(), $last->toDateString()])->count(),
                'leavers' => $leavers,
                'closing' => $closing,
                'turnover_percent' => Decimal::cmp($mean, 0) === 0 ? '0' : Decimal::round(Decimal::div(Decimal::mul($leavers, 100), $mean)),
            ];
        }

        $counts = Assignment::query()->activeOn($to)
            ->whereIn('employee_id', self::employedOn($to)->select('id'))
            ->selectRaw('org_unit_id, count(distinct employee_id) as headcount')
            ->groupBy('org_unit_id')
            ->pluck('headcount', 'org_unit_id');
        $units = OrgUnit::query()->whereIn('id', $counts->keys())->get()->keyBy('id');

        return [
            'months' => $months,
            'by_unit' => $counts->map(fn ($headcount, $unitId) => [
                'org_unit' => ['id' => (int) $unitId, 'name' => $units[$unitId]?->name],
                'headcount' => (int) $headcount,
            ])->values()->all(),
        ];
    }

    /**
     * Hired by that day and not past their last working day.
     *
     * @return Builder<Employee>
     */
    public static function employedOn(CarbonImmutable $day): Builder
    {
        return Employee::query()
            ->where('hired_on', '<=', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $day->toDateString()));
    }
}

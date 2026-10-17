<?php

namespace Add\Hr\Payroll\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of pay. A change replaces the employee's whole pay from a
 * date: every line running then is closed the day before, the new lines
 * open, and the change is a `compensation_change` on the career timeline.
 */
class CompensationService
{
    public function __construct(private readonly ExchangeRateProvider $rates) {}

    /**
     * @param  list<array{pay_component_id: int, amount: string|float|int, currency: string}>  $lines
     */
    public function change(Employee $employee, CarbonImmutable $from, array $lines, ?string $reason, int|string|null $actorId): Collection
    {
        return DB::transaction(function () use ($employee, $from, $lines, $reason, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (! $employee->isEmployedOn($from)) {
                HrException::fail('hr::time.not_employed');
            }

            if (Compensation::query()->where('employee_id', $employee->id)->where('effective_from', '>=', $from->toDateString())->exists()) {
                HrException::fail('hr::payroll.compensation_date_invalid');
            }

            $before = $this->linesOn($employee, $from->subDay());

            Compensation::query()
                ->where('employee_id', $employee->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString()]);

            $event = $employee->events()->create([
                'type' => EmploymentEventType::CompensationChange,
                'effective_on' => $from,
                'reason' => $reason,
                'payload' => ['before' => $before, 'after' => array_values($lines)],
                'recorded_by' => $actorId,
            ]);

            $created = collect($lines)->map(fn (array $line) => Compensation::query()->create([
                'employee_id' => $employee->id,
                'pay_component_id' => $line['pay_component_id'],
                'amount' => $line['amount'],
                'currency' => strtoupper($line['currency']),
                'effective_from' => $from,
                'event_id' => $event->id,
                'created_by' => $actorId,
            ]));

            HrAudit::record('compensation_changed', $employee, ['event_id' => $event->id]);

            return $created;
        });
    }

    /**
     * Monthly total of the given components' lines on a day, in a currency.
     *
     * @param  list<string>  $codes
     */
    public function monthlyTotal(Employee $employee, CarbonInterface $on, array $codes, string $currency): string
    {
        $ids = PayComponent::query()->whereIn('code', $codes)->pluck('id');
        $total = '0';

        foreach (Compensation::query()->where('employee_id', $employee->id)->whereIn('pay_component_id', $ids)->overlapping($on, $on)->get() as $line) {
            $rate = $this->rates->rate($line->currency, $currency);

            if ($rate === null) {
                HrException::fail('hr::payroll.missing_rate', ['from' => $line->currency, 'to' => $currency]);
            }

            $total = Decimal::add($total, Decimal::mul($line->amount, $rate));
        }

        return $total;
    }

    /**
     * @return list<array{pay_component_id: int, amount: string, currency: string}>
     */
    private function linesOn(Employee $employee, CarbonInterface $day): array
    {
        return Compensation::query()->where('employee_id', $employee->id)->overlapping($day, $day)->get()
            ->map(fn (Compensation $line) => ['pay_component_id' => $line->pay_component_id, 'amount' => $line->amount, 'currency' => $line->currency])
            ->values()
            ->all();
    }
}

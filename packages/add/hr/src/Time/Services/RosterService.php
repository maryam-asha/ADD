<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Models\RosterEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the roster. A past day's change rebuilds that day's attendance;
 * a locked month refuses any change.
 */
class RosterService
{
    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly AttendanceProcessor $processor,
        private readonly ScheduleResolver $schedule,
    ) {}

    /**
     * @param  list<array{employee_id: int, work_date: string, shift_template_id?: ?int, remove?: bool, note?: ?string}>  $entries
     */
    public function save(array $entries, int|string|null $actorId): int
    {
        return DB::transaction(function () use ($entries, $actorId) {
            foreach ($entries as $entry) {
                $date = CarbonImmutable::parse($entry['work_date']);
                $employee = Employee::query()->findOrFail($entry['employee_id']);
                $this->periods->assertOpen($date);

                if (! $employee->isEmployedOn($date)) {
                    HrException::fail('hr::time.not_employed');
                }

                $key = ['employee_id' => $employee->id, 'work_date' => $date->toDateString()];

                if (! empty($entry['remove'])) {
                    RosterEntry::query()->where($key)->delete();
                } else {
                    RosterEntry::query()->updateOrCreate($key, [
                        'shift_template_id' => $entry['shift_template_id'] ?? null,
                        'note' => $entry['note'] ?? null,
                        'created_by' => $actorId,
                    ]);
                }

                if ($date->lte($this->schedule->today())) {
                    $this->processor->process($employee, $date);
                }
            }

            HrAudit::record('roster_saved', null, ['entries' => count($entries)]);

            return count($entries);
        });
    }

    /**
     * Copies one week's entries (Monday-based or not: any 7 days from
     * `$from`) onto the 7 days from `$to`, for the given employees.
     *
     * @param  list<int>  $employeeIds
     */
    public function copyWeek(CarbonImmutable $from, CarbonImmutable $to, array $employeeIds, int|string|null $actorId): int
    {
        $offset = (int) $from->diffInDays($to);
        $entries = RosterEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$from->toDateString(), $from->addDays(6)->toDateString()])
            ->get()
            ->map(fn (RosterEntry $entry) => [
                'employee_id' => $entry->employee_id,
                'work_date' => $entry->work_date->addDays($offset)->toDateString(),
                'shift_template_id' => $entry->shift_template_id,
                'note' => $entry->note,
            ])
            ->filter(fn (array $entry) => Employee::query()->find($entry['employee_id'])?->isEmployedOn(CarbonImmutable::parse($entry['work_date'])))
            ->values()
            ->all();

        return $this->save($entries, $actorId);
    }
}

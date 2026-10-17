<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Support\Instant;
use Add\Hr\Time\Data\PunchResult;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Models\Punch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of punches, whatever their source: a device scanning a
 * card, an employee's own time once confirmed, or HR. A punch for someone
 * not employed that day, or in a locked month, is refused; a resend (same
 * idempotency key) or a second scan within hr.attendance.duplicate_seconds
 * returns the first punch instead of booking another. The affected days
 * are rebuilt in the same transaction.
 */
class PunchRecorder
{
    public function __construct(
        private readonly ScheduleResolver $schedule,
        private readonly PeriodGuard $periods,
        private readonly AttendanceProcessor $processor,
    ) {}

    /**
     * @param  array{device_type?: ?string, device_id?: ?string, idempotency_key?: ?string, reason?: ?string, recorded_by?: int|string|null}  $meta
     */
    public function record(Employee $employee, CarbonInterface $at, PunchSource $source, array $meta = []): PunchResult
    {
        $at = CarbonImmutable::parse($at)->utc()->startOfSecond();

        return DB::transaction(function () use ($employee, $at, $source, $meta) {
            $key = $meta['idempotency_key'] ?? null;

            if ($key !== null && ($existing = Punch::query()->where('idempotency_key', $key)->first()) !== null) {
                return new PunchResult($existing, true);
            }

            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $date = $this->schedule->localDate($at);

            if (! $employee->isEmployedOn($date) && ! $employee->isEmployedOn($date->subDay())) {
                HrException::fail('hr::time.not_employed');
            }

            $this->periods->assertOpen($date);

            $window = (int) config('hr.attendance.duplicate_seconds', 60);
            $twin = Punch::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('punched_at', [Instant::db($at->subSeconds($window)), Instant::db($at->addSeconds($window))])
                ->first();

            if ($twin !== null) {
                return new PunchResult($twin, true);
            }

            $punch = Punch::query()->create([
                'employee_id' => $employee->id,
                'punched_at' => $at,
                'source' => $source,
                'device_type' => $meta['device_type'] ?? null,
                'device_id' => $meta['device_id'] ?? null,
                'idempotency_key' => $key,
                'reason' => $meta['reason'] ?? null,
                'recorded_by' => $meta['recorded_by'] ?? null,
            ]);

            if ($source !== PunchSource::CardScan) {
                HrAudit::record('attendance_punch_recorded', $punch, ['source' => $source->value, 'reason' => $meta['reason'] ?? null]);
            }

            $this->processor->processAround($employee, $at);

            return new PunchResult($punch, false);
        });
    }
}

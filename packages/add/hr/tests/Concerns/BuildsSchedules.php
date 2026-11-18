<?php

namespace Add\Hr\Tests\Concerns;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Time\Data\PunchResult;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\ShiftTemplate;
use Add\Hr\Time\Models\WorkPattern;
use Add\Hr\Time\Services\PunchRecorder;
use Carbon\CarbonImmutable;

/**
 * Shifts, patterns and card punches for attendance tests, in local time
 * (hr.timezone = Asia/Damascus, UTC+3). Sunday to Thursday is the working
 * week of the default pattern.
 */
trait BuildsSchedules
{
    protected const TZ = 'Asia/Damascus';

    protected function useLocalTimezone(): void
    {
        config(['hr.timezone' => self::TZ]);
    }

    protected function shift(string $starts = '08:00', string $ends = '16:00', int $break = 30): ShiftTemplate
    {
        return ShiftTemplate::query()->create([
            'code' => 'shift-'.uniqid(),
            'name' => ['ar' => 'شيفت'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'break_minutes' => $break,
        ])->refresh();
    }

    /**
     * @param  list<int>  $days  ISO weekdays worked
     */
    protected function fixedPattern(ShiftTemplate $shift, array $days = [7, 1, 2, 3, 4]): WorkPattern
    {
        return WorkPattern::query()->create([
            'code' => 'pattern-'.uniqid(),
            'name' => ['ar' => 'نمط'],
            'kind' => 'fixed',
            'weekly' => collect(range(1, 7))->mapWithKeys(fn (int $d) => [(string) $d => in_array($d, $days, true) ? $shift->id : null])->all(),
        ])->refresh();
    }

    protected function punchAt(Employee $employee, string $local, PunchSource $source = PunchSource::CardScan, ?string $key = null): PunchResult
    {
        return app(PunchRecorder::class)->record(
            $employee,
            CarbonImmutable::parse($local, self::TZ),
            $source,
            ['device_type' => 'test', 'idempotency_key' => $key],
        );
    }

    protected function dayOf(Employee $employee, string $date): ?AttendanceDay
    {
        return AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date)->with('exceptions')->first();
    }

    /**
     * @return list<string>
     */
    protected function exceptionTypes(?AttendanceDay $day): array
    {
        return $day === null ? [] : $day->exceptions->map(fn ($e) => $e->type->value)->sort()->values()->all();
    }
}

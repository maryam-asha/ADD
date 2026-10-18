<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\ExceptionResolution;
use Add\Hr\Time\Enums\ExceptionStatus;
use Add\Hr\Time\Models\AttendanceException;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer closes an exception. On a missing record, `absent` and
 * `excused` decide the day's status; a correction is made by adding the
 * punch instead (the exception then goes away on its own).
 */
class ExceptionReview
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly PeriodGuard $periods,
    ) {}

    public function resolve(AttendanceException $exception, ExceptionResolution $resolution, ?string $note, int|string|null $actorId): AttendanceException
    {
        return DB::transaction(function () use ($exception, $resolution, $note, $actorId) {
            $exception = AttendanceException::query()->lockForUpdate()->findOrFail($exception->id);

            if ($exception->status !== ExceptionStatus::Open) {
                HrException::fail('hr::time.exception_resolved');
            }

            $this->periods->assertOpen($exception->work_date);

            $exception->update([
                'status' => ExceptionStatus::Resolved,
                'resolution' => $resolution,
                'note' => $note,
                'resolved_by' => $actorId,
                'resolved_at' => now(),
            ]);

            HrAudit::record('attendance_exception_resolved', $exception, ['resolution' => $resolution->value]);
            $this->processor->process($exception->employee, $exception->work_date);

            return $exception->refresh();
        });
    }
}

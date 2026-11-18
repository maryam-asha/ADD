<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Support\Instant;
use Add\Hr\Time\Enums\EntryStatus;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Models\AttendancePolicy;
use Add\Hr\Time\Models\Punch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The employee's own arrival and departure times. Each time is checked
 * against the card scans: when every time given has a scan within the
 * policy's tolerance, the entry is verified and nothing more happens (the
 * scans already count). Otherwise it goes to the manager; once approved,
 * the times it gave become punches.
 */
class AttendanceEntryService
{
    public const APPROVAL_TYPE = 'attendance_entry';

    public function __construct(
        private readonly ScheduleResolver $schedule,
        private readonly PeriodGuard $periods,
        private readonly ApprovalEngine $approvals,
        private readonly PunchRecorder $punches,
    ) {}

    /**
     * @param  array{work_date: string, check_in?: ?string, check_out?: ?string, note?: ?string}  $data  local H:i times
     */
    public function submit(Employee $employee, array $data, int|string|null $actorId): AttendanceEntry
    {
        return DB::transaction(function () use ($employee, $data, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $date = CarbonImmutable::parse($data['work_date']);

            if ($date->gt($this->schedule->today())) {
                HrException::fail('hr::time.entry_in_future');
            }

            if (! $employee->isEmployedOn($date)) {
                HrException::fail('hr::time.not_employed');
            }

            $this->periods->assertOpen($date);

            if (AttendanceEntry::query()->where('employee_id', $employee->id)->where('work_date', $date->toDateString())
                ->where('status', EntryStatus::Pending->value)->exists()) {
                HrException::fail('hr::time.entry_pending');
            }

            [$in, $out] = $this->instants($date, $data['check_in'] ?? null, $data['check_out'] ?? null);

            $entry = AttendanceEntry::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'check_in_at' => $in,
                'check_out_at' => $out,
                'note' => $data['note'] ?? null,
                'status' => $this->confirmedByScans($employee, $date, [$in, $out]) ? EntryStatus::Verified : EntryStatus::Pending,
                'submitted_by' => $actorId,
            ]);

            if ($entry->status === EntryStatus::Pending) {
                $entry->approval_request_id = $this->approvals->submit($entry, self::APPROVAL_TYPE, $employee, $actorId)->id;
                $entry->save();
            }

            HrAudit::record('attendance_entry_submitted', $entry, ['status' => $entry->status->value]);

            return $entry;
        });
    }

    public function cancel(AttendanceEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry = AttendanceEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== EntryStatus::Pending) {
                HrException::fail('hr::time.not_pending');
            }

            $this->approvals->cancel($entry->approval);
            $entry->update(['status' => EntryStatus::Cancelled]);
        });
    }

    /**
     * Approved: each time given becomes a punch (one already scanned nearby
     * is left alone), and the day is rebuilt.
     */
    public function applyApproved(AttendanceEntry $entry): void
    {
        $this->periods->assertOpen($entry->work_date);
        $employee = $entry->employee;

        foreach ([$entry->check_in_at, $entry->check_out_at] as $time) {
            if ($time !== null && ! $this->confirmedByScans($employee, $entry->work_date, [$time])) {
                $this->punches->record($employee, $time, PunchSource::ManualEntry, [
                    'reason' => $entry->note,
                    'idempotency_key' => "entry:{$entry->id}:".$time->getTimestamp(),
                ]);
            }
        }

        $entry->update(['status' => EntryStatus::Approved]);
    }

    /**
     * Local H:i on the work date, as UTC; a departure at or before the
     * arrival is on the next day (a night shift).
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function instants(CarbonImmutable $date, ?string $checkIn, ?string $checkOut): array
    {
        if ($checkIn === null && $checkOut === null) {
            HrException::fail('hr::time.entry_needs_a_time');
        }

        $timezone = $this->schedule->timezone();
        $in = $checkIn === null ? null : CarbonImmutable::parse($date->toDateString().' '.$checkIn, $timezone)->utc();
        $out = $checkOut === null ? null : CarbonImmutable::parse($date->toDateString().' '.$checkOut, $timezone)->utc();

        if ($in !== null && $out !== null && $out->lte($in)) {
            $out = $out->addDay();
        }

        return [$in, $out];
    }

    /**
     * @param  list<?CarbonImmutable>  $times
     */
    private function confirmedByScans(Employee $employee, CarbonImmutable $date, array $times): bool
    {
        $tolerance = AttendancePolicy::forDay($date)->entry_tolerance_minutes;

        foreach (array_filter($times) as $time) {
            $scanned = Punch::query()
                ->where('employee_id', $employee->id)
                ->where('source', PunchSource::CardScan->value)
                ->whereBetween('punched_at', [Instant::db($time->subMinutes($tolerance)), Instant::db($time->addMinutes($tolerance))])
                ->exists();

            if (! $scanned) {
                return false;
            }
        }

        return true;
    }
}

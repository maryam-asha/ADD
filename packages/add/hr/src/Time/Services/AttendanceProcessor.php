<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Time\Data\ScheduledDay;
use Add\Hr\Time\Enums\DayKind;
use Add\Hr\Time\Enums\DayStatus;
use Add\Hr\Time\Enums\ExceptionResolution;
use Add\Hr\Time\Enums\ExceptionStatus;
use Add\Hr\Time\Enums\ExceptionType;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\AttendancePolicy;
use Add\Hr\Time\Models\Punch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds one employee's work date from its plan and its punches, as a
 * fingerprint clock would: the first punch is the arrival, the last the
 * departure, and anything in between is ignored.
 *
 * - A planned working day with no punch is `no_record` — it needs a review,
 *   it is not an absence until a reviewer resolves it as one.
 * - A single punch is `incomplete` (missing_out) once the day is over.
 * - Late, early leave and overtime count only beyond the policy's grace and
 *   minimum; overtime is time worked past the shift's planned minutes, or
 *   all of it on a rest day, holiday or leave day (unscheduled_work).
 * - A day still running (today) is `open` and raises nothing yet.
 *
 * Open exceptions that no longer apply are removed; resolved ones stay as
 * history. Locked months are never touched. Idempotent: run it as often as
 * anything changes.
 */
class AttendanceProcessor
{
    public function __construct(
        private readonly ScheduleResolver $schedule,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * The days a new punch at `$instant` can belong to: its local date, and
     * the day before (a night shift).
     */
    public function processAround(Employee $employee, CarbonInterface $instant): void
    {
        $date = $this->schedule->localDate($instant);

        $this->process($employee, $date->subDay());
        $this->process($employee, $date);
    }

    public function process(Employee $employee, CarbonInterface $date): ?AttendanceDay
    {
        $date = CarbonImmutable::parse($date->toDateString());
        $existing = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date->toDateString())->first();

        if ($this->periods->isLocked($date)) {
            return $existing;
        }

        $plan = $this->schedule->for($employee, $date);
        $punches = $plan->kind === DayKind::NotEmployed ? collect() : $this->punchesFor($employee, $plan);

        // Nothing planned and nothing punched: attendance is not tracked.
        if ($plan->kind === DayKind::NotEmployed || ($punches->isEmpty() && ! $plan->planned)) {
            $existing?->delete();

            return null;
        }

        [$attributes, $types] = $this->evaluate($plan, $punches, $date->gte($this->schedule->today()));

        return DB::transaction(function () use ($employee, $date, $attributes, $types) {
            $day = AttendanceDay::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'work_date' => $date->toDateString()],
                $attributes,
            );

            $this->syncExceptions($day, $types);
            $this->applyResolutions($day);

            return $day->load('exceptions');
        });
    }

    /**
     * @param  Collection<int, Punch>  $punches
     * @return array{0: array<string, mixed>, 1: list<ExceptionType>}
     */
    private function evaluate(ScheduledDay $plan, Collection $punches, bool $running): array
    {
        $policy = AttendancePolicy::forDay($plan->date);
        $first = $punches->first()?->punched_at;
        $last = $punches->count() >= 2 ? $punches->last()->punched_at : null;
        $span = $last === null ? 0 : (int) $first->diffInMinutes($last);
        $break = $plan->shift?->break_minutes ?? 0;
        $worked = $span > $break ? $span - $break : $span;
        $late = $early = $overtime = 0;
        $types = [];

        if ($plan->isWorking()) {
            if ($first === null) {
                $status = $running ? DayStatus::Open : DayStatus::NoRecord;
                $types = $running ? [] : [ExceptionType::NoRecord];
            } elseif ($last === null) {
                $status = $running ? DayStatus::Open : DayStatus::Incomplete;
                $types = $running ? [] : [ExceptionType::MissingOut];
            } else {
                $status = DayStatus::Present;
            }

            if ($plan->kind === DayKind::Shift && $first !== null) {
                $lateBy = $first->gt($plan->start) ? (int) $plan->start->diffInMinutes($first) : 0;

                if ($lateBy > $policy->grace_minutes) {
                    $late = $lateBy;
                    $types[] = ExceptionType::Late;
                }

                if ($last !== null) {
                    $earlyBy = $last->lt($plan->end) ? (int) $last->diffInMinutes($plan->end) : 0;

                    if ($earlyBy > $policy->early_leave_grace_minutes) {
                        $early = $earlyBy;
                        $types[] = ExceptionType::EarlyLeave;
                    }

                    $extra = $worked - $plan->shift->plannedMinutes();
                    $overtime = $extra >= $policy->min_overtime_minutes ? $extra : 0;
                }
            }
        } elseif ($first === null) {
            $status = match ($plan->kind) {
                DayKind::Holiday => DayStatus::Holiday,
                DayKind::Leave => DayStatus::Leave,
                default => DayStatus::Rest,
            };
        } else {
            $status = $last !== null ? DayStatus::Present : ($running ? DayStatus::Open : DayStatus::Incomplete);
            $types[] = ExceptionType::UnscheduledWork;

            if ($last === null && ! $running) {
                $types[] = ExceptionType::MissingOut;
            }

            $overtime = $worked >= $policy->min_overtime_minutes ? $worked : 0;
        }

        return [[
            'status' => $status,
            'shift_template_id' => $plan->shift?->id,
            'scheduled_start' => $plan->start,
            'scheduled_end' => $plan->end,
            'first_in' => $first,
            'last_out' => $last,
            'punch_count' => $punches->count(),
            'worked_minutes' => $worked,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'overtime_minutes' => $overtime,
        ], $types];
    }

    /**
     * @param  list<ExceptionType>  $types
     */
    private function syncExceptions(AttendanceDay $day, array $types): void
    {
        $wanted = array_map(fn (ExceptionType $type) => $type->value, $types);

        $day->exceptions()
            ->where('status', ExceptionStatus::Open->value)
            ->whereNotIn('type', $wanted)
            ->delete();

        $present = $day->exceptions()->pluck('type')->map(fn ($type) => $type instanceof ExceptionType ? $type->value : $type)->all();

        foreach (array_diff($wanted, $present) as $type) {
            $day->exceptions()->create([
                'employee_id' => $day->employee_id,
                'work_date' => $day->work_date,
                'type' => $type,
                'status' => ExceptionStatus::Open,
            ]);
        }
    }

    /**
     * A reviewer's call on a missing record decides the day's status.
     */
    private function applyResolutions(AttendanceDay $day): void
    {
        if (! in_array($day->status, [DayStatus::NoRecord, DayStatus::Incomplete], true)) {
            return;
        }

        $resolution = $day->exceptions()
            ->where('status', ExceptionStatus::Resolved->value)
            ->whereIn('type', [ExceptionType::NoRecord->value, ExceptionType::MissingOut->value])
            ->latest('resolved_at')
            ->value('resolution');

        $status = match ($resolution instanceof ExceptionResolution ? $resolution : ExceptionResolution::tryFrom((string) $resolution)) {
            ExceptionResolution::Absent => DayStatus::Absent,
            ExceptionResolution::Excused => DayStatus::Excused,
            default => null,
        };

        if ($status !== null) {
            $day->update(['status' => $status]);
        }
    }

    /**
     * The punches of a work date: from local midnight to the next, except
     * that a night shift on either side takes the punches near its end.
     * Between a night shift's end and the next shift's start, the halfway
     * point divides them.
     *
     * @return Collection<int, Punch>
     */
    private function punchesFor(Employee $employee, ScheduledDay $plan): Collection
    {
        $timezone = $this->schedule->timezone();
        $margin = (int) config('hr.attendance.night_margin_minutes', 360);
        $lower = CarbonImmutable::parse($plan->date->toDateString(), $timezone)->utc();
        $upper = $lower->addDay();

        $previous = $this->schedule->planned($employee, $plan->date->subDay());

        if ($previous->crossesMidnight()) {
            $lower = $plan->start !== null ? $this->between($previous->end, $plan->start) : $previous->end->addMinutes($margin);
        }

        if ($plan->crossesMidnight()) {
            $next = $this->schedule->planned($employee, $plan->date->addDay());
            $upper = $next->start !== null ? $this->between($plan->end, $next->start) : $plan->end->addMinutes($margin);
        }

        return Punch::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $lower->format('Y-m-d H:i:s'))
            ->where('punched_at', '<', $upper->format('Y-m-d H:i:s'))
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();
    }

    private function between(CarbonImmutable $end, CarbonImmutable $nextStart): CarbonImmutable
    {
        return $nextStart->gt($end) ? $end->addSeconds((int) ($end->diffInSeconds($nextStart) / 2)) : $end;
    }
}

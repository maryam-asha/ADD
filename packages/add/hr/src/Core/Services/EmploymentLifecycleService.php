<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Events\EmployeeHired;
use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Core\Models\Assignment;
use Add\Hr\Core\Models\Contract;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmploymentEvent;
use Add\Hr\Core\Models\Position;
use Add\Hr\Core\PersonFields;
use Add\Hr\Hr;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of employments: hiring, and every later change to job,
 * contract and status, each recorded as an EmploymentEvent. Everything
 * happens in one transaction, so a rule that fails part-way leaves nothing
 * behind.
 */
class EmploymentLifecycleService
{
    /** Assignment fields, in a hire, a change or a rehire. */
    private const ASSIGNMENT_FIELDS = [
        'org_unit_id', 'position_id', 'job_grade_id', 'manager_id', 'work_location_id',
        'job_title', 'employment_type', 'work_mode', 'work_pattern_id',
    ];

    public function __construct(
        private readonly AccountProvisioner $accounts,
        private readonly EmployeeStatusResolver $statuses,
        private readonly ReportingLine $reportingLine,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the HireEmployeeRequest shape
     */
    public function hire(array $data, int|string|null $actorId): Employee
    {
        return DB::transaction(function () use ($data, $actorId) {
            $on = CarbonImmutable::parse($data['hired_on']);
            $assignment = Arr::only($data['assignment'], self::ASSIGNMENT_FIELDS);

            $this->assertAssignable($assignment, $on, null);

            $person = Arr::only($data, PersonFields::all());

            $employee = Employee::query()->create([
                'employee_number' => $data['employee_number'] ?? null,
                'hired_on' => $on,
                'probation_ends_on' => $data['contract']['probation_ends_on'] ?? null,
                // Placeholder for the NOT NULL column; refreshStatus() below
                // sets the real value once the events that decide it exist.
                'status' => EmployeeStatus::Active,
            ]);

            if (empty($employee->employee_number)) {
                $employee->employee_number = config('hr.employee_number.prefix')
                    .str_pad((string) $employee->id, (int) config('hr.employee_number.pad'), '0', STR_PAD_LEFT);
            }

            $this->linkAccount($employee, $data['user_id'] ?? null, $person);
            Hr::people()->put($employee, $person);

            $event = $this->recordEvent($employee, EmploymentEventType::Hire, $on, $actorId);
            $this->openContract($employee, $data['contract'], $on, $event);
            $this->openAssignment($employee, $assignment, $on, $event);

            $this->refreshStatus($employee);
            HrAudit::record('employee_hired', $employee, ['event_id' => $event->id]);

            EmployeeHired::dispatch($employee, $event);

            return $employee;
        });
    }

    /**
     * Any event after the hire. The employee row is locked for the whole
     * change, so two concurrent events cannot both pass the same check.
     *
     * @param  array<string, mixed>  $data  the RecordEmploymentEventRequest shape
     */
    public function record(Employee $employee, EmploymentEventType $type, array $data, int|string|null $actorId): EmploymentEvent
    {
        return DB::transaction(function () use ($employee, $type, $data, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $on = CarbonImmutable::parse($data['effective_on']);

            if ($type === EmploymentEventType::Termination && $employee->terminated_on !== null) {
                HrException::fail('hr::core.already_terminated');
            }

            if ($type !== EmploymentEventType::Rehire && ! $employee->isEmployedOn($on)) {
                HrException::fail('hr::core.not_employed_on_date');
            }

            $event = match ($type) {
                EmploymentEventType::Confirmation => $this->confirm($employee, $on, $data, $actorId),
                EmploymentEventType::Promotion,
                EmploymentEventType::Transfer,
                EmploymentEventType::AssignmentChange => $this->changeAssignment($employee, $type, $on, $data, $actorId),
                EmploymentEventType::ContractRenewal => $this->renewContract($employee, $on, $data, $actorId),
                EmploymentEventType::Suspension => $this->suspend($employee, $on, $data, $actorId),
                EmploymentEventType::Reinstatement => $this->reinstate($employee, $on, $data, $actorId),
                EmploymentEventType::Termination => $this->terminate($employee, $on, $data, $actorId),
                EmploymentEventType::Rehire => $this->rehire($employee, $on, $data, $actorId),
                EmploymentEventType::Hire,
                EmploymentEventType::CompensationChange => HrException::fail('hr::core.event_date_invalid'),
            };

            $this->refreshStatus($employee);
            HrAudit::record('employment_event_recorded', $employee, ['event_id' => $event->id, 'type' => $type->value]);

            return $event;
        });
    }

    /**
     * Tell the host the employment is over, exactly once. Runs for a
     * termination dated in the past and, for a future one, from
     * hr:refresh-statuses the day after the last working day.
     */
    public function revokeAccess(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($employee->access_revoked_at !== null || $employee->isEmployedOn(CarbonImmutable::today())) {
                return;
            }

            $this->accounts->deprovision($employee);

            $employee->access_revoked_at = now();
            $employee->save();

            HrAudit::record('access_revoked', $employee);
        });
    }

    /**
     * Recompute and store the cached status for today.
     */
    public function refreshStatus(Employee $employee): void
    {
        $employee->status = $this->statuses->resolve($employee, CarbonImmutable::today());
        $employee->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirm(Employee $employee, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        if ($employee->probation_ends_on === null || $employee->confirmed_on !== null) {
            HrException::fail('hr::core.not_on_probation');
        }

        $employee->confirmed_on = $on;

        return $this->recordEvent($employee, EmploymentEventType::Confirmation, $on, $actorId, notes: $data['notes'] ?? null);
    }

    /**
     * Close the assignment running that day and open a new one from it,
     * copying every field the event does not change. Moving to another unit
     * drops a position that belonged to the old one unless a new one is sent.
     *
     * @param  array<string, mixed>  $data
     */
    private function changeAssignment(Employee $employee, EmploymentEventType $type, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        $current = $employee->currentAssignment($on);
        $later = $employee->assignments()->where('effective_from', '>', $on->toDateString())->exists();

        if ($current === null || $later || $on->toDateString() <= $current->effective_from->toDateString()) {
            HrException::fail('hr::core.event_date_invalid');
        }

        $before = Arr::only($current->getAttributes(), self::ASSIGNMENT_FIELDS);
        $before['job_title'] = $current->job_title;
        $changes = Arr::only($data['assignment'] ?? [], self::ASSIGNMENT_FIELDS);

        if (array_key_exists('org_unit_id', $changes)
            && (int) $changes['org_unit_id'] !== (int) $before['org_unit_id']
            && ! array_key_exists('position_id', $changes)) {
            $changes['position_id'] = null;
        }

        $after = array_replace($before, $changes);
        $changed = array_keys(array_filter($after, fn ($value, $key) => $value != $before[$key], ARRAY_FILTER_USE_BOTH));

        $required = match ($type) {
            EmploymentEventType::Promotion => ['job_grade_id', 'position_id'],
            EmploymentEventType::Transfer => ['org_unit_id'],
            default => self::ASSIGNMENT_FIELDS,
        };

        if (array_intersect($changed, $required) === []) {
            HrException::fail('hr::core.event_changes_nothing');
        }

        $this->assertAssignable($after, $on, $employee);

        $current->effective_to = $on->subDay();
        $current->save();

        $event = $this->recordEvent($employee, $type, $on, $actorId,
            payload: ['before' => Arr::only($before, $changed), 'after' => Arr::only($after, $changed)],
            reason: $data['reason'] ?? null,
            notes: $data['notes'] ?? null,
        );

        // A termination already on file ends whatever opens before it.
        $this->openAssignment($employee, $after, $on, $event, $employee->terminated_on);

        return $event;
    }

    /**
     * A new contract starting after the last one has ended. An open-ended
     * contract never ends, so it is never renewed.
     *
     * @param  array<string, mixed>  $data
     */
    private function renewContract(Employee $employee, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        $last = $employee->contracts()->latest('starts_on')->first();

        if ($last !== null && ($last->ends_on === null || $on->toDateString() <= $last->ends_on->toDateString())) {
            HrException::fail('hr::core.contract_overlap');
        }

        $contract = $data['contract'];

        // A termination already on file ends the new contract too.
        if ($employee->terminated_on !== null
            && (empty($contract['ends_on'])
                || CarbonImmutable::parse($contract['ends_on'])->toDateString() > $employee->terminated_on->toDateString())) {
            $contract['ends_on'] = $employee->terminated_on->toDateString();
        }

        $event = $this->recordEvent($employee, EmploymentEventType::ContractRenewal, $on, $actorId, notes: $data['notes'] ?? null);
        $this->openContract($employee, $contract, $on, $event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function suspend(Employee $employee, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        if ($this->openSuspension($employee, $on) !== null) {
            HrException::fail('hr::core.already_suspended');
        }

        return $this->recordEvent($employee, EmploymentEventType::Suspension, $on, $actorId,
            reason: $data['reason'] ?? null,
            notes: $data['notes'] ?? null,
            to: isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to']) : null,
        );
    }

    /**
     * Ends the suspension running that day: it covers up to the day before.
     *
     * @param  array<string, mixed>  $data
     */
    private function reinstate(Employee $employee, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        $suspension = $this->openSuspension($employee, $on);

        if ($suspension === null) {
            HrException::fail('hr::core.not_suspended');
        }

        if ($on->toDateString() <= $suspension->effective_on->toDateString()) {
            HrException::fail('hr::core.event_date_invalid');
        }

        $suspension->effective_to = $on->subDay();
        $suspension->save();

        return $this->recordEvent($employee, EmploymentEventType::Reinstatement, $on, $actorId, notes: $data['notes'] ?? null);
    }

    /**
     * `effective_on` is the last working day. The assignment and contract end
     * that day; access is revoked once it has passed.
     *
     * @param  array<string, mixed>  $data
     */
    private function terminate(Employee $employee, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        $day = $on->toDateString();

        if ($employee->assignments()->where('effective_from', '>', $day)->exists()
            || $employee->contracts()->where('starts_on', '>', $day)->exists()) {
            HrException::fail('hr::core.event_date_invalid');
        }

        $employee->terminated_on = $on;
        $employee->termination_reason = $data['reason'];

        $employee->assignments()
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->update(['effective_to' => $day]);
        $employee->contracts()
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $day))
            ->update(['ends_on' => $day]);

        $event = $this->recordEvent($employee, EmploymentEventType::Termination, $on, $actorId,
            reason: $data['reason'],
            notes: $data['notes'] ?? null,
        );

        $employee->save();
        EmployeeTerminated::dispatch($employee, $event);

        if ($day < CarbonImmutable::today()->toDateString()) {
            $this->accounts->deprovision($employee);
            $employee->access_revoked_at = now();
            HrAudit::record('access_revoked', $employee);
        }

        return $event;
    }

    /**
     * A former employee comes back on the same record: a new employment
     * period with its own contract and assignment.
     *
     * @param  array<string, mixed>  $data
     */
    private function rehire(Employee $employee, CarbonImmutable $on, array $data, int|string|null $actorId): EmploymentEvent
    {
        // Only once the last working day has passed: a rehire rewrites the
        // employment's dates, which must not happen while it still runs.
        if ($employee->terminated_on === null
            || $employee->terminated_on->toDateString() >= CarbonImmutable::today()->toDateString()
            || $on->toDateString() <= $employee->terminated_on->toDateString()) {
            HrException::fail('hr::core.rehire_not_allowed');
        }

        $assignment = Arr::only($data['assignment'], self::ASSIGNMENT_FIELDS);
        $this->assertAssignable($assignment, $on, $employee);

        $employee->forceFill([
            'hired_on' => $on,
            'terminated_on' => null,
            'termination_reason' => null,
            'access_revoked_at' => null,
            'confirmed_on' => null,
            'probation_ends_on' => $data['contract']['probation_ends_on'] ?? null,
        ]);

        if ($employee->user_id === null) {
            $this->linkAccount($employee, null, collect(PersonFields::IDENTITY)->mapWithKeys(fn (string $field) => [$field => $employee->{$field}])->all());
        }

        $event = $this->recordEvent($employee, EmploymentEventType::Rehire, $on, $actorId, notes: $data['notes'] ?? null);
        $this->openContract($employee, $data['contract'], $on, $event);
        $this->openAssignment($employee, $assignment, $on, $event);

        $employee->save();
        EmployeeHired::dispatch($employee, $event);

        return $event;
    }

    private function openSuspension(Employee $employee, CarbonImmutable $on): ?EmploymentEvent
    {
        $day = $on->toDateString();

        return $employee->events()
            ->where('type', EmploymentEventType::Suspension->value)
            ->where('effective_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->latest('effective_on')
            ->first();
    }

    /**
     * A user given explicitly wins; otherwise the host may provision one.
     * With hr.require_user, an employee without an account is refused.
     */
    /**
     * @param  array<string, mixed>  $person  the hire's person fields, for provision()
     */
    private function linkAccount(Employee $employee, int|string|null $userId, array $person): void
    {
        if ($userId !== null) {
            $this->accounts->authorizeLink($userId);
        }

        $userId ??= $this->accounts->provision($employee, $person);

        if ($userId === null) {
            if (config('hr.require_user')) {
                HrException::fail('hr::core.user_required');
            }

            $employee->save();

            return;
        }

        if (Employee::query()->where('user_id', $userId)->whereKeyNot($employee->id)->exists()) {
            HrException::fail('hr::core.user_already_employee');
        }

        $employee->user_id = $userId;
        $employee->save();
    }

    /**
     * The position (if any) belongs to the chosen unit and has a free seat
     * from that day on, and the manager line is valid.
     *
     * @param  array<string, mixed>  $assignment
     */
    private function assertAssignable(array $assignment, CarbonInterface $on, ?Employee $employee): void
    {
        if (! empty($assignment['position_id'])) {
            // Locked so two hires into the last seat cannot both see it free.
            $position = Position::query()->lockForUpdate()->findOrFail($assignment['position_id']);

            if ((int) $position->org_unit_id !== (int) $assignment['org_unit_id']) {
                HrException::fail('hr::core.position_unit_mismatch');
            }

            $taken = Assignment::query()
                ->where('position_id', $position->id)
                ->openFrom($on)
                ->when($employee, fn ($q) => $q->where('employee_id', '!=', $employee->id))
                ->count();

            if ($taken >= $position->headcount) {
                HrException::fail('hr::core.position_full');
            }
        }

        $managerId = $assignment['manager_id'] ?? null;
        $this->reportingLine->assertValidManager($employee, $managerId === null ? null : (int) $managerId, $on);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        Employee $employee,
        EmploymentEventType $type,
        CarbonInterface $on,
        int|string|null $actorId,
        array $payload = [],
        ?string $reason = null,
        ?string $notes = null,
        ?CarbonInterface $to = null,
    ): EmploymentEvent {
        return $employee->events()->create([
            'type' => $type,
            'effective_on' => $on,
            'effective_to' => $to,
            'reason' => $reason,
            'notes' => $notes,
            'payload' => $payload ?: null,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function openContract(Employee $employee, array $contract, CarbonInterface $startsOn, EmploymentEvent $event): Contract
    {
        return $employee->contracts()->create([
            'type' => $contract['type'],
            'starts_on' => $startsOn,
            'ends_on' => $contract['ends_on'] ?? null,
            'notice_period_days' => $contract['notice_period_days'] ?? null,
            'reference' => $contract['reference'] ?? null,
            'event_id' => $event->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function openAssignment(Employee $employee, array $assignment, CarbonInterface $from, EmploymentEvent $event, ?CarbonInterface $to = null): Assignment
    {
        return $employee->assignments()->create([
            ...$assignment,
            'effective_from' => $from,
            'effective_to' => $to,
            'event_id' => $event->id,
        ]);
    }
}

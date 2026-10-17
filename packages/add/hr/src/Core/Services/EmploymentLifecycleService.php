<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Events\EmployeeHired;
use Add\Hr\Core\Models\Assignment;
use Add\Hr\Core\Models\Contract;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmploymentEvent;
use Add\Hr\Core\Models\Position;
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
    /** Personal fields a hire request may set on the employee row. */
    private const PERSONAL_FIELDS = [
        'employee_number', 'name', 'gender', 'date_of_birth', 'nationality', 'marital_status',
        'phone', 'personal_email', 'work_email', 'address', 'national_id', 'bank_account',
    ];

    /** Assignment fields, in a hire, a change or a rehire. */
    private const ASSIGNMENT_FIELDS = [
        'org_unit_id', 'position_id', 'job_grade_id', 'manager_id', 'work_location_id',
        'job_title', 'employment_type', 'work_mode',
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

            $employee = Employee::query()->create([
                ...Arr::only($data, self::PERSONAL_FIELDS),
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

            $this->linkAccount($employee, $data['user_id'] ?? null);

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
     * Recompute and store the cached status for today.
     */
    public function refreshStatus(Employee $employee): void
    {
        $employee->status = $this->statuses->resolve($employee, CarbonImmutable::today());
        $employee->save();
    }

    /**
     * A user given explicitly wins; otherwise the host may provision one.
     * With hr.require_user, an employee without an account is refused.
     */
    private function linkAccount(Employee $employee, int|string|null $userId): void
    {
        $userId ??= $this->accounts->provision($employee);

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
            $position = Position::query()->findOrFail($assignment['position_id']);

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
    private function openAssignment(Employee $employee, array $assignment, CarbonInterface $from, EmploymentEvent $event): Assignment
    {
        return $employee->assignments()->create([
            ...$assignment,
            'effective_from' => $from,
            'event_id' => $event->id,
        ]);
    }
}

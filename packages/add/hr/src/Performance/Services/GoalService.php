<?php

namespace Add\Hr\Performance\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Performance\Enums\GoalStatus;
use Add\Hr\Performance\Models\Goal;
use Add\Hr\Performance\Models\ReviewCycle;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Goals: set by HR, by the employee, or by their direct manager. What a goal
 * is and weighs is fixed once its cycle leaves goal setting; its progress
 * and status move until the cycle closes. An employee's goals in a cycle
 * weigh 100 at most.
 */
class GoalService
{
    /** Fields fixed once the cycle leaves goal setting. */
    private const DEFINITION = ['title', 'description', 'metric', 'target', 'weight', 'due_on'];

    public function __construct(private readonly HrClock $clock) {}

    /**
     * May `$actor` set and update `$owner`'s goals from self-service: their
     * own, or their direct reports'.
     */
    public function canManage(Employee $actor, Employee $owner): bool
    {
        return $actor->id === $owner->id
            || $owner->currentAssignment(CarbonImmutable::now($this->clock->timezone()))?->manager_id === $actor->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data, int|string|null $actorId): Goal
    {
        return DB::transaction(function () use ($employee, $data, $actorId) {
            $cycle = isset($data['review_cycle_id']) ? ReviewCycle::query()->lockForUpdate()->findOrFail($data['review_cycle_id']) : null;
            if ($cycle !== null) {
                if (! $cycle->status->goalsOpen()) {
                    HrException::fail('hr::performance.goals_locked');
                }
                if ($cycle->status !== CycleStatus::Draft && ! $cycle->reviews()->where('employee_id', $employee->id)->exists()) {
                    HrException::fail('hr::performance.not_in_cycle');
                }
                $this->assertWeight($employee->id, $cycle->id, (int) ($data['weight'] ?? 0), null);
            }

            $goal = Goal::query()->create([
                'employee_id' => $employee->id,
                'review_cycle_id' => $cycle?->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'metric' => $data['metric'] ?? null,
                'target' => $data['target'] ?? null,
                'weight' => $data['weight'] ?? 0,
                'progress' => $data['progress'] ?? 0,
                'status' => GoalStatus::Active,
                'due_on' => $data['due_on'] ?? null,
                'created_by' => $actorId,
            ]);
            HrAudit::record('goal_set', $goal);

            return $goal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Goal $goal, array $data): Goal
    {
        return DB::transaction(function () use ($goal, $data) {
            $goal = Goal::query()->with('cycle')->lockForUpdate()->findOrFail($goal->id);
            $status = $goal->cycle?->status;
            if ($status === CycleStatus::Closed) {
                HrException::fail('hr::performance.cycle_closed');
            }
            if ($status !== null && ! $status->goalsOpen() && array_intersect(array_keys($data), self::DEFINITION) !== []) {
                HrException::fail('hr::performance.goals_locked');
            }
            if ($goal->review_cycle_id !== null && (array_key_exists('weight', $data) || ($data['status'] ?? null) === GoalStatus::Active->value)) {
                $this->assertWeight($goal->employee_id, $goal->review_cycle_id, (int) ($data['weight'] ?? $goal->weight), $goal->id);
            }

            $goal->update($data);
            HrAudit::record('goal_updated', $goal, ['changes' => array_keys($goal->getChanges())]);

            return $goal;
        });
    }

    public function delete(Goal $goal): void
    {
        $status = $goal->cycle?->status;
        if ($status !== null && ! $status->goalsOpen()) {
            HrException::fail('hr::performance.goals_locked');
        }

        HrAudit::record('goal_deleted', $goal);
        $goal->delete();
    }

    private function assertWeight(int $employeeId, int $cycleId, int $weight, ?int $except): void
    {
        $others = (int) Goal::query()
            ->where('employee_id', $employeeId)
            ->where('review_cycle_id', $cycleId)
            ->where('status', '!=', GoalStatus::Cancelled->value)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->sum('weight');

        if ($others + $weight > 100) {
            HrException::fail('hr::performance.goals_weight_exceeded', ['left' => max(0, 100 - $others)]);
        }
    }
}

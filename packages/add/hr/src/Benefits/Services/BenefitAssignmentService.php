<?php

namespace Add\Hr\Benefits\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitAssignment;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Who receives a benefit. Assignments of one benefit to one target never
 * overlap in time, are ended rather than edited, and are deleted only
 * before anything was granted under them.
 */
class BenefitAssignmentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function assign(Benefit $benefit, array $data, int|string|null $actorId): BenefitAssignment
    {
        return DB::transaction(function () use ($benefit, $data, $actorId) {
            $benefit = Benefit::query()->lockForUpdate()->findOrFail($benefit->id);
            $target = array_filter([
                'employee_id' => $data['employee_id'] ?? null,
                'job_grade_id' => $data['job_grade_id'] ?? null,
                'org_unit_id' => $data['org_unit_id'] ?? null,
            ], fn ($id) => $id !== null);
            $from = CarbonImmutable::parse($data['effective_from']);
            $to = isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to']) : null;

            $this->assertNoOverlap($benefit, $target, $from, $to);

            $assignment = $benefit->assignments()->create([
                ...$target,
                'amount' => $data['amount'] ?? null,
                'employer_cost' => $data['employer_cost'] ?? null,
                'excluded' => (bool) ($data['excluded'] ?? false),
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
                'created_by' => $actorId,
            ]);

            HrAudit::record('benefit_assigned', $assignment, ['benefit' => $benefit->code, ...$target]);

            return $assignment->refresh();
        });
    }

    public function end(BenefitAssignment $assignment, CarbonImmutable $on): BenefitAssignment
    {
        if ($on->lessThan($assignment->effective_from)) {
            HrException::fail('hr::benefits.end_before_start');
        }

        if ($assignment->effective_to !== null && $on->greaterThan($assignment->effective_to)) {
            HrException::fail('hr::benefits.end_after_current');
        }

        $assignment->update(['effective_to' => $on->toDateString()]);
        HrAudit::record('benefit_assignment_ended', $assignment, ['effective_to' => $on->toDateString()]);

        return $assignment;
    }

    public function delete(BenefitAssignment $assignment): void
    {
        if (BenefitGrant::query()->where('benefit_assignment_id', $assignment->id)->exists()) {
            HrException::fail('hr::benefits.assignment_in_use', [], 409);
        }

        HrAudit::record('benefit_assignment_deleted', $assignment);
        $assignment->delete();
    }

    /**
     * @param  array<string, int|string>  $target
     */
    private function assertNoOverlap(Benefit $benefit, array $target, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $overlaps = $benefit->assignments()
            ->where($target)
            ->when($to, fn ($q) => $q->where('effective_from', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
            ->exists();

        if ($overlaps) {
            HrException::fail('hr::benefits.assignment_overlap');
        }
    }
}

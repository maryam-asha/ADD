<?php

namespace Add\Hr\Benefits\Services;

use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitAssignment;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The benefits an employee holds on a day. Among the assignments in force
 * that reach them, the most specific decides — their own, then their
 * grade's, then their unit's (the nearest unit up the tree) — then the most
 * recent. An `excluded` assignment that decides means none.
 */
class BenefitResolver
{
    /**
     * @return Collection<int, BenefitAssignment> one deciding assignment per
     *                                            benefit, the benefit loaded
     */
    public function for(Employee $employee, CarbonInterface $on, ?Collection $benefits = null): Collection
    {
        $benefits ??= Benefit::query()->where('is_active', true)->get();

        if ($benefits->isEmpty()) {
            return collect();
        }

        $assignment = $employee->currentAssignment($on);
        $units = $this->unitChain($assignment?->org_unit_id);

        return BenefitAssignment::query()
            ->whereIn('benefit_id', $benefits->modelKeys())
            ->activeOn($on)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->when($assignment?->job_grade_id, fn ($q, $grade) => $q->orWhere('job_grade_id', $grade))
                ->when($units !== [], fn ($q) => $q->orWhereIn('org_unit_id', array_keys($units))))
            ->get()
            ->groupBy('benefit_id')
            ->map(fn (Collection $candidates) => $candidates
                ->sort(fn (BenefitAssignment $a, BenefitAssignment $b) => [$this->rank($b, $units), $b->effective_from->toDateString(), $b->id]
                    <=> [$this->rank($a, $units), $a->effective_from->toDateString(), $a->id])
                ->first())
            ->reject(fn (BenefitAssignment $decided) => $decided->excluded)
            ->each(fn (BenefitAssignment $decided) => $decided->setRelation('benefit', $benefits->find($decided->benefit_id)))
            ->values();
    }

    /**
     * The value of one grant under that assignment.
     */
    public function amount(BenefitAssignment $assignment): string
    {
        return $assignment->amount ?? $assignment->benefit->amount;
    }

    public function employerCost(BenefitAssignment $assignment): ?string
    {
        return $assignment->employer_cost ?? $assignment->benefit->employer_cost;
    }

    /**
     * @param  array<int, int>  $units  unit id => distance from the employee's unit
     */
    private function rank(BenefitAssignment $assignment, array $units): int
    {
        return match (true) {
            $assignment->employee_id !== null => 3000,
            $assignment->job_grade_id !== null => 2000,
            default => 1000 - ($units[$assignment->org_unit_id] ?? 999),
        };
    }

    /**
     * @return array<int, int> the unit and its ancestors, by distance
     */
    private function unitChain(?int $unitId): array
    {
        $chain = [];

        while ($unitId !== null && ! array_key_exists($unitId, $chain)) {
            $chain[$unitId] = count($chain);
            $unitId = OrgUnit::query()->whereKey($unitId)->value('parent_id');
        }

        return $chain;
    }
}

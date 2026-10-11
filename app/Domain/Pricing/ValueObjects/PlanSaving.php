<?php

namespace App\Domain\Pricing\ValueObjects;

/**
 * What a plan saves against its reference — a plan (`comparedTo` 'plan')
 * or a space's hourly rate ('space') — times `units`
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 * It is the plan's own price, not a discount: shown and recorded, never
 * taken off anything. `perUnitPoints` is exact (a bcmath string).
 * `referenceDays` is a reference plan's period — what one unit is (a day, a
 * week, a month) — null for a space, whose unit is an hour; `planDays` is
 * the plan's own period (a day pass is a day, not its hours). Display only:
 * not part of toArray(), the copy the discount ledger keeps.
 */
final class PlanSaving
{
    public function __construct(
        public readonly string $comparedTo,
        public readonly int $referenceId,
        public readonly ?string $referenceName,
        public readonly int $units,
        public readonly int $referencePoints,
        public readonly int $savingPoints,
        public readonly int $savingPercent,
        public readonly string $perUnitPoints,
        public readonly ?int $referenceDays = null,
        public readonly ?int $planDays = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'compared_to' => $this->comparedTo,
            'reference_id' => $this->referenceId,
            'reference_name' => $this->referenceName,
            'units' => $this->units,
            'reference_points' => $this->referencePoints,
            'saving_points' => $this->savingPoints,
            'saving_percent' => $this->savingPercent,
            'per_unit_points' => $this->perUnitPoints,
        ];
    }
}

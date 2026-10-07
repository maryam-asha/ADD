<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\ValueObjects\PlanSaving;

/**
 * A plan's built-in duration or volume saving
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md):
 *
 *   reference_points = the reference's LIST price × units   (E7: never its offer price)
 *   saving_points    = reference_points − the plan's price  (none when ≤ 0)
 *   saving_percent   = saving_points × 100 / reference_points, rounded down
 *   per_unit_points  = the plan's price / units, exact
 */
class PlanSavingCalculator
{
    public function for(Plan $plan): ?PlanSaving
    {
        $units = (int) $plan->reference_units;

        [$comparedTo, $reference, $unitPrice] = match (true) {
            $plan->reference_plan_id !== null && $plan->referencePlan !== null => ['plan', $plan->referencePlan, (int) $plan->referencePlan->price_points],
            $plan->reference_space_id !== null && $plan->referenceSpace !== null => ['space', $plan->referenceSpace, (int) ($plan->referenceSpace->hourly_rate_points ?? 0)],
            default => [null, null, 0],
        };

        if ($reference === null || $units < 1 || $unitPrice <= 0) {
            return null;
        }

        $referencePoints = $unitPrice * $units;
        $savingPoints = $referencePoints - (int) $plan->price_points;

        if ($savingPoints <= 0) {
            return null;
        }

        return new PlanSaving(
            comparedTo: $comparedTo,
            referenceId: $reference->id,
            referenceName: $comparedTo === 'plan' ? $reference->translate('name') : $reference->displayTitle(),
            units: $units,
            referencePoints: $referencePoints,
            savingPoints: $savingPoints,
            savingPercent: intdiv($savingPoints * 100, $referencePoints),
            perUnitPoints: bcdiv((string) $plan->price_points, (string) $units, 2),
        );
    }
}

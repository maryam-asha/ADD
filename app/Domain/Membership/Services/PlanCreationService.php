<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Creates a plan with its balances, features, discounts and resource
 * grants in one transaction — for an admin (PlanController::store) and for
 * an approved provider design (PlanDesignHandler).
 */
class PlanCreationService
{
    /** @param  array<string, mixed>  $validated  PlanRules-shaped */
    public function create(array $validated): Plan
    {
        $balances = $validated['balances'] ?? [];
        $features = $validated['features'] ?? [];
        $discounts = $validated['discounts'] ?? [];
        $grants = $validated['resource_grants'] ?? [];
        unset($validated['balances'], $validated['features'], $validated['discounts'], $validated['resource_grants']);

        return DB::transaction(function () use ($validated, $balances, $features, $discounts, $grants) {
            $plan = Plan::create(array_merge(['is_active' => true, 'order' => 0], $validated));

            if ($balances !== []) {
                $plan->balances()->createMany($balances);
            }

            if ($features !== []) {
                $plan->features()->createMany($features);
            }

            if ($discounts !== []) {
                $plan->discounts()->createMany($discounts);
            }

            if ($grants !== []) {
                $plan->resourceGrants()->createMany($grants);
            }

            return $plan;
        });
    }
}

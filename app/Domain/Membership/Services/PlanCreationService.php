<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
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
        $validated = $this->withCoworkingTerms($validated);
        $balances = $validated['balances'] ?? [];
        $features = $validated['features'] ?? [];
        $discounts = $validated['discounts'] ?? [];
        $grants = $validated['resource_grants'] ?? [];
        $spaceHours = $validated['space_hours'] ?? [];
        unset($validated['balances'], $validated['features'], $validated['discounts'], $validated['resource_grants'], $validated['space_hours']);

        return DB::transaction(function () use ($validated, $balances, $features, $discounts, $grants, $spaceHours) {
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

            if ($spaceHours !== []) {
                $plan->spaceHours()->createMany($spaceHours);
            }

            return $plan;
        });
    }

    /**
     * A design that does not state its coworking terms but still carries the
     * old space-hours resource grant — a provider's, made before plans had
     * their own terms — gets a flexible seat with those hours instead of the
     * grant, as the conversion migration read every plan of that shape.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function withCoworkingTerms(array $validated): array
    {
        if (array_key_exists('coworking_seat', $validated)) {
            return $validated;
        }

        $hoursType = ResourceType::query()->where('slug', CoworkingPlanClassifier::LEGACY_HOURS_SLUG)->value('id');
        $grants = collect($validated['resource_grants'] ?? []);
        $legacy = $grants->first(fn (array $grant) => (int) ($grant['resource_type_id'] ?? 0) === (int) $hoursType);

        if ($hoursType === null || $legacy === null) {
            return $validated;
        }

        $validated['coworking_seat'] = CoworkingSeat::Flexible->value;
        $validated['coworking_hours'] = $legacy['quantity'];
        $validated['resource_grants'] = $grants->reject(fn (array $grant) => (int) ($grant['resource_type_id'] ?? 0) === (int) $hoursType)->values()->all();

        return $validated;
    }
}

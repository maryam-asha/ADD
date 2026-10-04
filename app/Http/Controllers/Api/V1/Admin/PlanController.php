<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscount;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class PlanController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Plan::class;
    }

    protected function resourceClass(): string
    {
        return PlanResource::class;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->with(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType']);
    }

    public function show(int $id): JsonResource
    {
        $plan = Plan::with(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType'])->findOrFail($id);

        return new PlanResource($plan);
    }

    public function store(StorePlanRequest $request): PlanResource
    {
        $validated = $request->validated();
        $balancesData = $validated['balances'] ?? [];
        $featuresData = $validated['features'] ?? [];
        $discountsData = $validated['discounts'] ?? [];
        $resourceGrantsData = $validated['resource_grants'] ?? [];
        unset($validated['balances'], $validated['features'], $validated['discounts'], $validated['resource_grants']);

        $plan = DB::transaction(function () use ($validated, $balancesData, $featuresData, $discountsData, $resourceGrantsData) {
            $plan = Plan::create(array_merge(
                ['is_active' => true, 'order' => 0],
                $validated
            ));

            if (! empty($balancesData)) {
                $plan->balances()->createMany($balancesData);
            }

            if (! empty($featuresData)) {
                $plan->features()->createMany($featuresData);
            }

            if (! empty($discountsData)) {
                $plan->discounts()->createMany($discountsData);
            }

            if (! empty($resourceGrantsData)) {
                $plan->resourceGrants()->createMany($resourceGrantsData);
            }

            return $plan;
        });

        return new PlanResource($plan->load(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType']));
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $validated = $request->validated();
        $hasBalances = array_key_exists('balances', $validated);
        $hasFeatures = array_key_exists('features', $validated);
        $hasDiscounts = array_key_exists('discounts', $validated);
        $hasResourceGrants = array_key_exists('resource_grants', $validated);
        $balancesData = $validated['balances'] ?? [];
        $featuresData = $validated['features'] ?? [];
        $discountsData = $validated['discounts'] ?? [];
        $resourceGrantsData = $validated['resource_grants'] ?? [];
        unset($validated['balances'], $validated['features'], $validated['discounts'], $validated['resource_grants']);

        DB::transaction(function () use ($plan, $validated, $hasBalances, $balancesData, $hasFeatures, $featuresData, $hasDiscounts, $discountsData, $hasResourceGrants, $resourceGrantsData) {
            $plan->update($validated);

            if ($hasBalances) {
                $plan->balances()->delete();
                if (! empty($balancesData)) {
                    $plan->balances()->createMany($balancesData);
                }
            }

            if ($hasFeatures) {
                $plan->features()->delete();
                if (! empty($featuresData)) {
                    $plan->features()->createMany($featuresData);
                }
            }

            if ($hasDiscounts) {
                $this->reconcileDiscounts($plan, $discountsData);
            }

            if ($hasResourceGrants) {
                $plan->resourceGrants()->delete();
                if (! empty($resourceGrantsData)) {
                    $plan->resourceGrants()->createMany($resourceGrantsData);
                }
            }
        });

        return response()->json(['message' => __('api.admin.plan_updated')]);
    }

    /**
     * Discounts named by `id` are edited in place and the rest created. One
     * left out of the payload is deleted — unless a booking was priced with
     * it: its redemption rows are the booking's record of what it got (what
     * an extension and a check-out are priced from, and what `usage_limit`
     * counts), so it is switched off instead (docs/decisions/plan-discounts-design.md).
     *
     * @param  list<array<string, mixed>>  $discountsData
     */
    private function reconcileDiscounts(Plan $plan, array $discountsData): void
    {
        $kept = [];

        foreach ($discountsData as $data) {
            $id = $data['id'] ?? null;
            unset($data['id']);

            if ($id !== null) {
                $plan->discounts()->whereKey($id)->firstOrFail()->update($data);
                $kept[] = (int) $id;
            } else {
                $kept[] = $plan->discounts()->create($data)->id;
            }
        }

        $plan->discounts()
            ->whereNotIn('id', $kept)
            ->get()
            ->each(fn (PlanDiscount $discount) => $discount->redemptions()->exists()
                ? $discount->update(['is_active' => false])
                : $discount->delete());
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Membership\Models\Plan;
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
        $query->with(['planModule', 'balances', 'features']);
    }

    public function show(int $id): JsonResource
    {
        $plan = Plan::with(['planModule', 'balances', 'features'])->findOrFail($id);

        return new PlanResource($plan);
    }

    public function store(StorePlanRequest $request): PlanResource
    {
        $validated = $request->validated();
        $balancesData = $validated['balances'] ?? [];
        $featuresData = $validated['features'] ?? [];
        unset($validated['balances'], $validated['features']);

        $plan = DB::transaction(function () use ($validated, $balancesData, $featuresData) {
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

            return $plan;
        });

        return new PlanResource($plan->load(['planModule', 'balances', 'features']));
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $validated = $request->validated();
        $hasBalances = array_key_exists('balances', $validated);
        $hasFeatures = array_key_exists('features', $validated);
        $balancesData = $validated['balances'] ?? [];
        $featuresData = $validated['features'] ?? [];
        unset($validated['balances'], $validated['features']);

        DB::transaction(function () use ($plan, $validated, $hasBalances, $balancesData, $hasFeatures, $featuresData) {
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
        });

        return response()->json(['message' => __('api.admin.plan_updated')]);
    }
}

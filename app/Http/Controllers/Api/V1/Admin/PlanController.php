<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Membership\Models\Plan;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use Illuminate\Http\JsonResponse;

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

    /**
     * `is_active`/`order` are set explicitly here, not left to the
     * migration's column defaults — Eloquent doesn't re-fetch DB-side
     * defaults into an unrefreshed model, so omitting either would
     * otherwise come back `null` in this very response even though the DB
     * row is correctly `true`/`0` (the same lesson already documented for
     * `CompanyController::store` and `UserFactory`).
     */
    public function store(StorePlanRequest $request): PlanResource
    {
        return new PlanResource(Plan::create(array_merge(
            ['is_active' => true, 'order' => 0],
            $request->validated()
        )));
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return response()->json(['message' => __('api.admin.plan_updated')]);
    }
}

<?php

namespace Add\Hr\Timesheets\Http\Controllers\Admin;

use Add\Hr\Support\HrException;
use Add\Hr\Timesheets\Http\Requests\StoreCostCenterRequest;
use Add\Hr\Timesheets\Http\Requests\UpdateCostCenterRequest;
use Add\Hr\Timesheets\Http\Resources\CostCenterResource;
use Add\Hr\Timesheets\Models\CostAllocation;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Models\TimesheetEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CostCenterController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CostCenterResource::collection(CostCenter::query()->orderBy('code')->get());
    }

    public function store(StoreCostCenterRequest $request): CostCenterResource
    {
        return new CostCenterResource(CostCenter::query()->create($request->validated())->refresh());
    }

    public function show(CostCenter $costCenter): CostCenterResource
    {
        return new CostCenterResource($costCenter);
    }

    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter): JsonResponse
    {
        $costCenter->update($request->validated());

        return response()->json(['message' => __('hr::timesheets.cost_center_updated')]);
    }

    /**
     * Charged hours or splits keep a cost center; deactivate it instead.
     */
    public function destroy(CostCenter $costCenter): Response
    {
        if (TimesheetEntry::query()->where('cost_center_id', $costCenter->id)->exists()
            || CostAllocation::query()->where('cost_center_id', $costCenter->id)->exists()) {
            HrException::fail('hr::timesheets.cost_center_in_use', [], 409);
        }

        $costCenter->delete();

        return response()->noContent();
    }
}

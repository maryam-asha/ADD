<?php

namespace Add\Hr\Timesheets\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Timesheets\Http\Requests\ReplaceCostAllocationRequest;
use Add\Hr\Timesheets\Http\Resources\CostAllocationResource;
use Add\Hr\Timesheets\Models\CostAllocation;
use Add\Hr\Timesheets\Services\CostAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * An employee's planned cost split and its history.
 */
class CostAllocationController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return CostAllocationResource::collection(
            CostAllocation::query()->where('employee_id', $employee->id)->with('costCenter')
                ->orderBy('effective_from')->orderBy('id')->get()
        );
    }

    public function replace(ReplaceCostAllocationRequest $request, Employee $employee, CostAllocationService $allocations): JsonResponse
    {
        $allocations->replace(
            $employee,
            CarbonImmutable::parse($request->validated('effective_from')),
            $request->validated('lines'),
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['message' => __('hr::timesheets.allocation_updated')]);
    }
}

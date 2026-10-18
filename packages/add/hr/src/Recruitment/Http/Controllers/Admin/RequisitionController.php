<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Http\Requests\StoreRequisitionRequest;
use Add\Hr\Recruitment\Http\Resources\RequisitionResource;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Recruitment\Services\RequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class RequisitionController extends Controller
{
    public function __construct(private readonly RequisitionService $requisitions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RequisitionResource::collection(
            Requisition::query()->with(['orgUnit', 'hiringManager'])
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->query('org_unit_id'), fn ($q, $id) => $q->where('org_unit_id', $id))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function store(StoreRequisitionRequest $request): RequisitionResource
    {
        $requisition = $this->requisitions->file(
            $request->validated(),
            Employee::query()->findOrFail($request->validated('hiring_manager_id')),
            $request->user()?->getAuthIdentifier(),
        );

        return new RequisitionResource($requisition->load(['orgUnit', 'hiringManager']));
    }

    public function show(Requisition $requisition): RequisitionResource
    {
        return new RequisitionResource($requisition->load(['orgUnit', 'hiringManager']));
    }

    public function cancel(Requisition $requisition): JsonResponse
    {
        $this->requisitions->cancel($requisition);

        return response()->json(['message' => __('hr::recruitment.requisition_cancelled')]);
    }

    public function close(Requisition $requisition): JsonResponse
    {
        $this->requisitions->close($requisition);

        return response()->json(['message' => __('hr::recruitment.requisition_closed')]);
    }
}

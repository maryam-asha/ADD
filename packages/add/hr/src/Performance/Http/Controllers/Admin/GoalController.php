<?php

namespace Add\Hr\Performance\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Http\Requests\GoalRequest;
use Add\Hr\Performance\Http\Resources\GoalResource;
use Add\Hr\Performance\Models\Goal;
use Add\Hr\Performance\Services\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class GoalController extends Controller
{
    public function __construct(private readonly GoalService $goals) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return GoalResource::collection(
            Goal::query()
                ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->when($request->query('review_cycle_id'), fn ($q, $id) => $q->where('review_cycle_id', $id))
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('id')
                ->paginate(100)
        );
    }

    public function store(GoalRequest $request, Employee $employee): GoalResource
    {
        return new GoalResource($this->goals->create($employee, $request->safe()->except('employee_id'), $request->user()?->getAuthIdentifier()));
    }

    public function update(GoalRequest $request, Goal $goal): JsonResponse
    {
        $this->goals->update($goal, $request->validated());

        return response()->json(['message' => __('hr::performance.goal_updated')]);
    }

    public function destroy(Goal $goal): Response
    {
        $this->goals->delete($goal);

        return response()->noContent();
    }
}

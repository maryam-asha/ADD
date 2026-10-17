<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Http\Requests\HireEmployeeRequest;
use Add\Hr\Core\Http\Requests\UpdateEmployeeRequest;
use Add\Hr\Core\Http\Resources\EmployeeResource;
use Add\Hr\Core\Http\Resources\EmployeeSummaryResource;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * Filters: `status`, `org_unit_id` (today's assignment), and `q` — the
     * employee number or either language of the name.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'org_unit_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $employees = Employee::query()
            ->with(['activeAssignment.orgUnit', 'activeAssignment.position'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('org_unit_id'), fn ($q, $unitId) => $q->whereHas(
                'assignments',
                fn ($a) => $a->activeOn(CarbonImmutable::today())->where('org_unit_id', $unitId),
            ))
            ->when($request->query('q'), function ($q, string $term) {
                // JSON columns hold non-ASCII text \u-escaped on SQLite and
                // as-is on MySQL, so search both spellings of the name.
                $escaped = trim(json_encode($term), '"');

                $q->where(fn ($w) => $w->where('employee_number', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$escaped}%"));
            })
            ->orderBy('id')
            ->paginate(25);

        return EmployeeSummaryResource::collection($employees);
    }

    public function store(HireEmployeeRequest $request, EmploymentLifecycleService $lifecycle): EmployeeResource
    {
        $employee = $lifecycle->hire($request->validated(), $request->user()?->getAuthIdentifier());

        return new EmployeeResource($this->loadFile($employee));
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->loadFile($employee));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee->update($request->validated());

        return response()->json(['message' => __('hr::core.employee_updated')]);
    }

    private function loadFile(Employee $employee): Employee
    {
        return $employee->load([
            'activeAssignment.orgUnit',
            'activeAssignment.position',
            'activeAssignment.jobGrade',
            'activeAssignment.manager',
            'activeAssignment.workLocation',
            'activeContract',
            'contacts',
            'dependents',
        ]);
    }
}

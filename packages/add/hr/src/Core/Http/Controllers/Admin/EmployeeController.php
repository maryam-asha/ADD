<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Http\Requests\HireEmployeeRequest;
use Add\Hr\Core\Http\Requests\UpdateEmployeeRequest;
use Add\Hr\Core\Http\Resources\EmployeeResource;
use Add\Hr\Core\Http\Resources\EmployeeSummaryResource;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Hr;
use Add\Hr\Reports\Support\CsvExport;
use Add\Hr\Support\LocalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    /** The orders the list can be read in. */
    private const SORTS = ['newest', 'oldest', 'name_asc', 'name_desc'];

    /**
     * Filters: `status`, `org_unit_id` (today's assignment), `user_id`, and
     * `q` — the employee number, or the person's name, phone or email
     * wherever the PersonStore keeps them.
     *
     * `sort`: `newest` / `oldest` (by when the record was added) or
     * `name_asc` / `name_desc` (in the request's language). Without it the
     * list keeps its original order, oldest record first.
     *
     * `format=csv` downloads every matching employee (not one page), or only
     * those named in `ids[]`; the export is audited.
     */
    public function index(Request $request): AnonymousResourceCollection|StreamedResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'org_unit_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'format' => ['nullable', 'in:json,csv'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $employees = Employee::query()
            ->with(['activeAssignment.orgUnit', 'activeAssignment.position', 'activeAssignment.manager'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('org_unit_id'), fn ($q, $unitId) => $q->whereHas(
                'assignments',
                fn ($a) => $a->activeOn(LocalDay::today())->where('org_unit_id', $unitId),
            ))
            ->when($request->query('q'), fn ($q, string $term) => $q->where(
                fn ($w) => $w->where('employee_number', 'like', "%{$term}%")
                    ->orWhere(fn ($person) => Hr::people()->search($person, $term)),
            ))
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($data['ids'] ?? null, fn ($q, array $ids) => $q->whereIn('id', $ids));

        match ($data['sort'] ?? null) {
            'newest' => $employees->orderByDesc('id'),
            'name_asc' => Hr::people()->orderByName($employees, 'asc'),
            'name_desc' => Hr::people()->orderByName($employees, 'desc'),
            default => null,
        };
        // Last, so equal names keep a stable order across pages.
        $employees->orderBy('id', ($data['sort'] ?? null) === 'newest' ? 'desc' : 'asc');

        if (($data['format'] ?? null) === 'csv') {
            return $this->csv($employees->get());
        }

        return EmployeeSummaryResource::collection($employees->paginate(25));
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
        DB::transaction(fn () => Hr::people()->put($employee, $request->validated()));

        return response()->json(['message' => __('hr::core.employee_updated')]);
    }

    /**
     * The list as a spreadsheet: the same columns the dashboard shows,
     * statuses and employment types as their keys, names in the request's
     * language.
     *
     * @param  iterable<Employee>  $employees
     */
    private function csv(iterable $employees): StreamedResponse
    {
        $rows = [];
        foreach ($employees as $employee) {
            $assignment = $employee->activeAssignment;
            $rows[] = [
                $employee->employee_number,
                CsvExport::label($employee->name),
                $employee->status->value,
                $employee->work_email,
                CsvExport::label($assignment?->resolvedTitle()),
                CsvExport::label($assignment?->orgUnit?->name),
                CsvExport::label($assignment?->manager?->name),
                $assignment?->employment_type?->value,
                $employee->hired_on->toDateString(),
            ];
        }

        return CsvExport::download('employees', ['on' => LocalDay::today()->toDateString()],
            ['employee_number', 'employee', 'status', 'work_email', 'job_title', 'org_unit', 'manager', 'employment_type', 'hired_on'],
            $rows);
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

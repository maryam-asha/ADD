<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Http\Requests\RecordEmploymentEventRequest;
use Add\Hr\Core\Http\Resources\AssignmentResource;
use Add\Hr\Core\Http\Resources\ContractResource;
use Add\Hr\Core\Http\Resources\EmploymentEventResource;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * An employee's career: recording events, and reading the history they
 * leave (events, assignments, contracts), oldest first.
 */
class EmploymentEventController extends Controller
{
    public function store(RecordEmploymentEventRequest $request, Employee $employee, EmploymentLifecycleService $lifecycle): EmploymentEventResource
    {
        $event = $lifecycle->record(
            $employee,
            EmploymentEventType::from($request->validated('type')),
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
        );

        return new EmploymentEventResource($event);
    }

    public function events(Employee $employee): AnonymousResourceCollection
    {
        return EmploymentEventResource::collection(
            $employee->events()->orderBy('effective_on')->orderBy('id')->get()
        );
    }

    public function assignments(Employee $employee): AnonymousResourceCollection
    {
        return AssignmentResource::collection(
            $employee->assignments()
                ->with(['orgUnit', 'position', 'jobGrade', 'manager', 'workLocation'])
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get()
        );
    }

    public function contracts(Employee $employee): AnonymousResourceCollection
    {
        return ContractResource::collection($employee->contracts()->orderBy('starts_on')->orderBy('id')->get());
    }
}

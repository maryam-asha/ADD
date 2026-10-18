<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\ReplaceContactsRequest;
use Add\Hr\Core\Http\Requests\ReplaceDependentsRequest;
use Add\Hr\Core\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Emergency contacts and dependents, each replaced as a whole list.
 */
class EmployeeRelationsController extends Controller
{
    public function contacts(ReplaceContactsRequest $request, Employee $employee): JsonResponse
    {
        DB::transaction(function () use ($request, $employee) {
            $employee->contacts()->delete();
            $employee->contacts()->createMany($request->validated('contacts'));
        });

        return response()->json(['message' => __('hr::core.contacts_updated')]);
    }

    public function dependents(ReplaceDependentsRequest $request, Employee $employee): JsonResponse
    {
        DB::transaction(function () use ($request, $employee) {
            $employee->dependents()->delete();
            $employee->dependents()->createMany($request->validated('dependents'));
        });

        return response()->json(['message' => __('hr::core.dependents_updated')]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use Add\Hr\Core\Http\Resources\EmployeeSummaryResource;
use Add\Hr\Core\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    /**
     * Shared by both the member app and the ops dashboard — same User model,
     * same shape, regardless of whether the request authenticated via an API
     * token (Sanctum) or a first-party SPA session (Fortify + Sanctum SPA).
     *
     * `employee` is the account's HR record (packages/add/hr) or null: the
     * dashboard shows "My HR" from it, since being an employee is not a role.
     */
    public function show(Request $request): JsonResponse
    {
        $employee = Employee::forUser($request->user());

        return response()->json([
            'user' => new UserResource($request->user()),
            'employee' => $employee === null
                ? null
                : new EmployeeSummaryResource($employee->load(['activeAssignment.orgUnit', 'activeAssignment.position'])),
        ]);
    }
}

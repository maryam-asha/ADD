<?php

namespace Add\Hr\Payroll\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Http\Requests\ChangeCompensationRequest;
use Add\Hr\Payroll\Http\Resources\CompensationResource;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Services\CompensationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * An employee's pay and its history.
 */
class CompensationController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return CompensationResource::collection(
            Compensation::query()->where('employee_id', $employee->id)->with('component')
                ->orderBy('effective_from')->orderBy('id')->get()
        );
    }

    public function change(ChangeCompensationRequest $request, Employee $employee, CompensationService $compensation): JsonResponse
    {
        $compensation->change(
            $employee,
            CarbonImmutable::parse($request->validated('effective_from')),
            $request->validated('lines'),
            $request->validated('reason'),
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['message' => __('hr::payroll.compensation_updated')]);
    }
}

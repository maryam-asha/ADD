<?php

namespace Add\Hr\Payroll\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Http\Requests\RequestLoanRequest;
use Add\Hr\Payroll\Http\Requests\SettleLoanRequest;
use Add\Hr\Payroll\Http\Resources\LoanResource;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Payroll\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Every loan; HR asking one for an employee (it still goes through the
 * approval flow); settling what is left outside payroll.
 */
class LoanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LoanResource::collection(
            Loan::query()->with(['employee', 'schedule'])
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(Loan $loan): LoanResource
    {
        return new LoanResource($loan->load(['employee', 'schedule']));
    }

    public function store(RequestLoanRequest $request, Employee $employee, LoanService $loans): LoanResource
    {
        return new LoanResource($loans->request($employee, $request->validated(), $request->user()?->getAuthIdentifier())->load(['employee', 'schedule']));
    }

    public function settle(SettleLoanRequest $request, Loan $loan, LoanService $loans): JsonResponse
    {
        $loans->settle($loan, $request->validated('note'), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::payroll.loan_settled')]);
    }
}

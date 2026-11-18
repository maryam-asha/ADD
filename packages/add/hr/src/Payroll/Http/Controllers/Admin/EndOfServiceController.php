<?php

namespace Add\Hr\Payroll\Http\Controllers\Admin;

use Add\Hr\Contracts\EndOfServiceCalculator;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * End-of-service entitlement for one employee, and the liability for
 * everyone employed on a day.
 */
class EndOfServiceController extends Controller
{
    public function show(Request $request, Employee $employee, EndOfServiceCalculator $calculator): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        return response()->json(['data' => $calculator->estimate($employee, CarbonImmutable::parse($request->input('as_of', 'today')))]);
    }

    public function liability(Request $request, EndOfServiceCalculator $calculator): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = CarbonImmutable::parse($request->input('as_of', 'today'));
        $rows = [];
        $totals = [];

        $employees = Employee::query()
            ->where('hired_on', '<=', $asOf->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $asOf->toDateString()))
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            $estimate = $calculator->estimate($employee, $asOf);
            $totals[$estimate['currency']] = Decimal::add($totals[$estimate['currency']] ?? '0', $estimate['amount']);
            $rows[] = ['employee' => ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->name], ...$estimate];
        }

        return response()->json(['data' => [
            'as_of' => $asOf->toDateString(),
            'totals' => array_map(fn (string $total) => Decimal::trim($total), $totals),
            'employees' => $rows,
        ]]);
    }
}

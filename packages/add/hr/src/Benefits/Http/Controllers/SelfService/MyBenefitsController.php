<?php

namespace Add\Hr\Benefits\Http\Controllers\SelfService;

use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Benefits\Models\BenefitAssignment;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Benefits\Services\BenefitResolver;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * My benefits and what I received. Costs, delivery references and failure
 * reasons are HR's.
 */
class MyBenefitsController extends Controller
{
    public function index(Request $request, BenefitResolver $resolver, HrClock $clock): JsonResponse
    {
        $employee = Employee::forUser($request->user());

        return response()->json(['data' => [
            'benefits' => $resolver->for($employee, CarbonImmutable::now($clock->timezone())->startOfDay())->map(fn (BenefitAssignment $held) => [
                'code' => $held->benefit->code,
                'name' => $held->benefit->name,
                'description' => $held->benefit->description,
                'kind' => $held->benefit->kind->value,
                'frequency' => $held->benefit->frequency->value,
                'amount' => $resolver->amount($held),
                'currency' => $held->benefit->currency,
                'unit' => $held->benefit->unit,
            ])->values(),
            'grants' => BenefitGrant::query()
                ->where('employee_id', $employee->id)
                ->where('status', '!=', BenefitGrantStatus::Cancelled->value)
                ->with('benefit')
                ->latest('granted_on')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (BenefitGrant $grant) => [
                    'id' => $grant->id,
                    'benefit' => ['code' => $grant->benefit->code, 'name' => $grant->benefit->name, 'kind' => $grant->benefit->kind->value],
                    'period' => $grant->period,
                    'granted_on' => $grant->granted_on->toDateString(),
                    'amount' => $grant->amount,
                    'currency' => $grant->currency,
                    'unit' => $grant->unit,
                    'status' => $grant->status->value,
                ]),
        ]]);
    }
}

<?php

namespace Add\Hr\Payroll\Http\Controllers\Admin;

use Add\Hr\Payroll\Calculation\CalculatorRegistry;
use Add\Hr\Payroll\Http\Requests\StorePayComponentRequest;
use Add\Hr\Payroll\Http\Requests\UpdatePayComponentRequest;
use Add\Hr\Payroll\Http\Resources\PayComponentResource;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\PayslipLine;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PayComponentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PayComponentResource::collection(PayComponent::query()->orderBy('sort')->orderBy('id')->get());
    }

    /**
     * The calculator keys a component may use.
     */
    public function calculators(CalculatorRegistry $registry): JsonResponse
    {
        return response()->json(['data' => array_keys($registry->all())]);
    }

    public function store(StorePayComponentRequest $request): PayComponentResource
    {
        return new PayComponentResource(PayComponent::query()->create($request->validated())->refresh());
    }

    public function show(PayComponent $payComponent): PayComponentResource
    {
        return new PayComponentResource($payComponent);
    }

    public function update(UpdatePayComponentRequest $request, PayComponent $payComponent): JsonResponse
    {
        $payComponent->update($request->validated());

        return response()->json(['message' => __('hr::payroll.component_updated')]);
    }

    public function destroy(PayComponent $payComponent): Response
    {
        if (Compensation::query()->where('pay_component_id', $payComponent->id)->exists()
            || PayslipLine::query()->where('pay_component_id', $payComponent->id)->exists()) {
            HrException::fail('hr::payroll.component_in_use', [], 409);
        }

        $payComponent->delete();

        return response()->noContent();
    }
}

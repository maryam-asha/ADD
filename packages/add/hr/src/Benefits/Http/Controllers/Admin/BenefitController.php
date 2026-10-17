<?php

namespace Add\Hr\Benefits\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Benefits\Http\Requests\StoreBenefitRequest;
use Add\Hr\Benefits\Http\Requests\UpdateBenefitRequest;
use Add\Hr\Benefits\Http\Resources\BenefitAssignmentResource;
use Add\Hr\Benefits\Http\Resources\BenefitGrantResource;
use Add\Hr\Benefits\Http\Resources\BenefitResource;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Benefits\Services\BenefitResolver;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class BenefitController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BenefitResource::collection(Benefit::query()->withCount('assignments')->orderBy('code')->get());
    }

    public function store(StoreBenefitRequest $request): BenefitResource
    {
        $benefit = Benefit::query()->create($request->validated());
        HrAudit::record('benefit_created', $benefit);

        return new BenefitResource($benefit->refresh());
    }

    public function show(Benefit $benefit): BenefitResource
    {
        return new BenefitResource($benefit->loadCount('assignments'));
    }

    public function update(UpdateBenefitRequest $request, Benefit $benefit): JsonResponse
    {
        $benefit->update($request->validated());
        HrAudit::record('benefit_updated', $benefit, ['changes' => array_keys($benefit->getChanges())]);

        return response()->json(['message' => __('hr::benefits.benefit_updated')]);
    }

    public function destroy(Benefit $benefit): Response
    {
        if ($benefit->grants()->exists()) {
            HrException::fail('hr::benefits.benefit_in_use', [], 409);
        }

        HrAudit::record('benefit_deleted', $benefit);
        $benefit->delete();

        return response()->noContent();
    }

    /**
     * The benefits an employee holds on a day (default today) and where
     * each comes from, with their latest grants.
     */
    public function forEmployee(Request $request, Employee $employee, BenefitResolver $resolver): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = CarbonImmutable::parse($request->input('as_of', 'today'));
        $held = $resolver->for($employee, $asOf)->each->load(['jobGrade', 'orgUnit']);

        return response()->json(['data' => [
            'as_of' => $asOf->toDateString(),
            'benefits' => BenefitAssignmentResource::collection($held)->resolve($request),
            'grants' => BenefitGrantResource::collection(
                BenefitGrant::query()->where('employee_id', $employee->id)->with('benefit')->latest('granted_on')->latest('id')->limit(50)->get()
            )->resolve($request),
        ]]);
    }
}

<?php

namespace Add\Hr\Benefits\Http\Controllers\Admin;

use Add\Hr\Benefits\Http\Requests\EndBenefitAssignmentRequest;
use Add\Hr\Benefits\Http\Requests\StoreBenefitAssignmentRequest;
use Add\Hr\Benefits\Http\Resources\BenefitAssignmentResource;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitAssignment;
use Add\Hr\Benefits\Services\BenefitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class BenefitAssignmentController extends Controller
{
    public function __construct(private readonly BenefitAssignmentService $assignments) {}

    public function index(Benefit $benefit): AnonymousResourceCollection
    {
        return BenefitAssignmentResource::collection(
            $benefit->assignments()->with(['employee', 'jobGrade', 'orgUnit'])->orderByDesc('effective_from')->orderByDesc('id')->get()
        );
    }

    public function store(StoreBenefitAssignmentRequest $request, Benefit $benefit): BenefitAssignmentResource
    {
        $assignment = $this->assignments->assign($benefit, $request->validated(), $request->user()?->getAuthIdentifier());

        return new BenefitAssignmentResource($assignment->load(['employee', 'jobGrade', 'orgUnit']));
    }

    public function end(EndBenefitAssignmentRequest $request, BenefitAssignment $assignment): JsonResponse
    {
        $this->assignments->end($assignment, CarbonImmutable::parse($request->validated('effective_to')));

        return response()->json(['message' => __('hr::benefits.assignment_ended')]);
    }

    public function destroy(BenefitAssignment $assignment): Response
    {
        $this->assignments->delete($assignment);

        return response()->noContent();
    }
}

<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Leave\Http\Requests\StoreCoverageRuleRequest;
use Add\Hr\Leave\Http\Requests\UpdateCoverageRuleRequest;
use Add\Hr\Leave\Http\Resources\CoverageRuleResource;
use Add\Hr\Leave\Models\CoverageRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CoverageRuleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CoverageRuleResource::collection(CoverageRule::query()->orderBy('id')->get());
    }

    public function store(StoreCoverageRuleRequest $request): CoverageRuleResource
    {
        return new CoverageRuleResource(CoverageRule::query()->create($request->validated())->refresh());
    }

    public function show(CoverageRule $coverageRule): CoverageRuleResource
    {
        return new CoverageRuleResource($coverageRule);
    }

    public function update(UpdateCoverageRuleRequest $request, CoverageRule $coverageRule): JsonResponse
    {
        $coverageRule->update($request->validated());

        return response()->json(['message' => __('hr::leave.coverage_rule_updated')]);
    }

    public function destroy(CoverageRule $coverageRule): Response
    {
        $coverageRule->delete();

        return response()->noContent();
    }
}

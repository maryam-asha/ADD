<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Http\Requests\StorePipelineStageRequest;
use Add\Hr\Recruitment\Http\Requests\UpdatePipelineStageRequest;
use Add\Hr\Recruitment\Http\Resources\PipelineStageResource;
use Add\Hr\Recruitment\Models\PipelineStage;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PipelineStageController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PipelineStageResource::collection(PipelineStage::query()->orderBy('sort')->orderBy('id')->get());
    }

    public function store(StorePipelineStageRequest $request): PipelineStageResource
    {
        $stage = PipelineStage::query()->create([...$request->validated(), 'type' => StageType::Open]);
        HrAudit::record('pipeline_stage_created', $stage);

        return new PipelineStageResource($stage->refresh());
    }

    public function update(UpdatePipelineStageRequest $request, PipelineStage $stage): JsonResponse
    {
        if ($stage->type !== StageType::Open) {
            HrException::fail('hr::recruitment.stage_fixed');
        }

        $stage->update($request->validated());
        HrAudit::record('pipeline_stage_updated', $stage);

        return response()->json(['message' => __('hr::recruitment.stage_updated')]);
    }
}

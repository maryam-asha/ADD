<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\StoreJobGradeRequest;
use Add\Hr\Core\Http\Requests\UpdateJobGradeRequest;
use Add\Hr\Core\Http\Resources\JobGradeResource;
use Add\Hr\Core\Models\JobGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class JobGradeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JobGradeResource::collection(JobGrade::query()->orderBy('rank')->orderBy('id')->get());
    }

    public function store(StoreJobGradeRequest $request): JobGradeResource
    {
        return new JobGradeResource(JobGrade::query()->create($request->validated()));
    }

    public function show(JobGrade $jobGrade): JobGradeResource
    {
        return new JobGradeResource($jobGrade);
    }

    public function update(UpdateJobGradeRequest $request, JobGrade $jobGrade): JsonResponse
    {
        $jobGrade->update($request->validated());

        return response()->json(['message' => __('hr::core.job_grade_updated')]);
    }

    public function destroy(JobGrade $jobGrade): Response
    {
        $jobGrade->delete();

        return response()->noContent();
    }
}

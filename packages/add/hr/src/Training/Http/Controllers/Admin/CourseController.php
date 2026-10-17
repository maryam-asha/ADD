<?php

namespace Add\Hr\Training\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Training\Http\Requests\CourseRequest;
use Add\Hr\Training\Http\Resources\CourseResource;
use Add\Hr\Training\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CourseResource::collection(
            Course::query()
                ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                ->orderBy('code')
                ->get()
        );
    }

    public function show(Course $course): CourseResource
    {
        return new CourseResource($course);
    }

    public function store(CourseRequest $request): CourseResource
    {
        $course = Course::query()->create($request->validated());
        HrAudit::record('course_created', $course);

        return new CourseResource($course);
    }

    public function update(CourseRequest $request, Course $course): JsonResponse
    {
        $course->update($request->validated());
        HrAudit::record('course_updated', $course, ['changes' => array_keys($course->getChanges())]);

        return response()->json(['message' => __('hr::training.course_updated')]);
    }
}

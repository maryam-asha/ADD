<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Core\Models\Assignment;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Http\Requests\StoreWorkPatternRequest;
use Add\Hr\Time\Http\Requests\UpdateWorkPatternRequest;
use Add\Hr\Time\Http\Resources\WorkPatternResource;
use Add\Hr\Time\Models\WorkPattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * A pattern on any assignment, past or present, stays; deactivate it instead.
 */
class WorkPatternController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WorkPatternResource::collection(WorkPattern::query()->orderBy('id')->get());
    }

    public function store(StoreWorkPatternRequest $request): WorkPatternResource
    {
        return new WorkPatternResource(WorkPattern::query()->create($request->attributesToSave())->refresh());
    }

    public function show(WorkPattern $workPattern): WorkPatternResource
    {
        return new WorkPatternResource($workPattern);
    }

    public function update(UpdateWorkPatternRequest $request, WorkPattern $workPattern): JsonResponse
    {
        $workPattern->update($request->attributesToSave());

        return response()->json(['message' => __('hr::time.pattern_updated')]);
    }

    public function destroy(WorkPattern $workPattern): Response
    {
        if (Assignment::query()->where('work_pattern_id', $workPattern->id)->exists()) {
            HrException::fail('hr::time.pattern_in_use', [], 409);
        }

        $workPattern->delete();

        return response()->noContent();
    }
}

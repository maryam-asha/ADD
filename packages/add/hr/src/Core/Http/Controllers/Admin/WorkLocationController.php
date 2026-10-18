<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\StoreWorkLocationRequest;
use Add\Hr\Core\Http\Requests\UpdateWorkLocationRequest;
use Add\Hr\Core\Http\Resources\WorkLocationResource;
use Add\Hr\Core\Models\WorkLocation;
use Add\Hr\Hr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WorkLocationController extends Controller
{
    /**
     * HR's own locations in full; a host's (config hr.work_locations.model)
     * as {id, name} only, since the host manages them.
     */
    public function index(): AnonymousResourceCollection|JsonResponse
    {
        if (! Hr::ownsWorkLocations()) {
            $model = Hr::workLocationModel();

            return response()->json([
                'data' => $model::query()->orderBy('id')->get()
                    ->map(fn (Model $location) => ['id' => $location->getKey(), 'name' => $location->getAttribute('name')])
                    ->values(),
            ]);
        }

        return WorkLocationResource::collection(WorkLocation::query()->orderBy('id')->get());
    }

    public function store(StoreWorkLocationRequest $request): WorkLocationResource
    {
        return new WorkLocationResource(WorkLocation::query()->create($request->validated()));
    }

    public function show(WorkLocation $workLocation): WorkLocationResource
    {
        return new WorkLocationResource($workLocation);
    }

    public function update(UpdateWorkLocationRequest $request, WorkLocation $workLocation): JsonResponse
    {
        $workLocation->update($request->validated());

        return response()->json(['message' => __('hr::core.work_location_updated')]);
    }

    public function destroy(WorkLocation $workLocation): Response
    {
        $workLocation->delete();

        return response()->noContent();
    }
}

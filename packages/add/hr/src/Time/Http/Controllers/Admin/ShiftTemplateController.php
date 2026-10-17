<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Support\HrException;
use Add\Hr\Time\Http\Requests\StoreShiftTemplateRequest;
use Add\Hr\Time\Http\Requests\UpdateShiftTemplateRequest;
use Add\Hr\Time\Http\Resources\ShiftTemplateResource;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\RosterEntry;
use Add\Hr\Time\Models\ShiftTemplate;
use Add\Hr\Time\Models\WorkPattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * A shift already planned or worked stays; deactivate it instead.
 */
class ShiftTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ShiftTemplateResource::collection(ShiftTemplate::query()->orderBy('id')->get());
    }

    public function store(StoreShiftTemplateRequest $request): ShiftTemplateResource
    {
        return new ShiftTemplateResource(ShiftTemplate::query()->create($request->validated())->refresh());
    }

    public function show(ShiftTemplate $shiftTemplate): ShiftTemplateResource
    {
        return new ShiftTemplateResource($shiftTemplate);
    }

    public function update(UpdateShiftTemplateRequest $request, ShiftTemplate $shiftTemplate): JsonResponse
    {
        $shiftTemplate->update($request->validated());

        return response()->json(['message' => __('hr::time.shift_updated')]);
    }

    public function destroy(ShiftTemplate $shiftTemplate): Response
    {
        if (RosterEntry::query()->where('shift_template_id', $shiftTemplate->id)->exists()
            || AttendanceDay::query()->where('shift_template_id', $shiftTemplate->id)->exists()
            || WorkPattern::query()->get()->contains(fn ($pattern) => in_array($shiftTemplate->id, array_filter($pattern->weekly ?? []), false))) {
            HrException::fail('hr::time.shift_in_use', [], 409);
        }

        $shiftTemplate->delete();

        return response()->noContent();
    }
}

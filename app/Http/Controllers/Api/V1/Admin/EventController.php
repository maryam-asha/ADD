<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventLocationService;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Resources\EventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Event::class;
    }

    protected function resourceClass(): string
    {
        return EventResource::class;
    }

    public function store(StoreEventRequest $request, EventLocationService $locationService): EventResource
    {
        $validated = $request->validated();
        $header = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language')));
        $locale = str_contains($header, 'en') ? 'en' : 'ar';

        $branchId = $validated['branch_id'] ?? null;
        $spaceId = $validated['space_id'] ?? null;
        $room = $validated['room'] ?? null;
        $explicitLocation = $validated['location'] ?? null;

        $computedLocation = $locationService->resolveLocation(
            $branchId,
            $spaceId,
            $room,
            $explicitLocation,
            $locale
        );

        if ($computedLocation !== null) {
            $validated['location'] = $computedLocation;
        }
        if ($branchId !== null) {
            $validated['branch_id'] = $branchId;
        }

        if ($spaceId && empty($validated['capacity'])) {
            $space = \App\Domain\Foundation\Models\Space::find($spaceId);
            if ($space?->capacity) {
                $validated['capacity'] = (int) $space->capacity;
            }
        }

        unset($validated['room'], $validated['room_id']);

        return new EventResource(Event::create(array_merge(
            ['order' => 0, 'status' => 'draft'],
            $validated
        )));
    }

    public function update(UpdateEventRequest $request, Event $event, EventLocationService $locationService): JsonResponse
    {
        $validated = $request->validated();
        $header = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language')));
        $locale = str_contains($header, 'en') ? 'en' : 'ar';

        $branchId = $validated['branch_id'] ?? $event->branch_id;
        $spaceId = array_key_exists('space_id', $validated) ? $validated['space_id'] : $event->space_id;
        $room = $validated['room'] ?? null;
        $explicitLocation = $validated['location'] ?? null;

        if ($spaceId || $room || $explicitLocation || (isset($validated['branch_id']) && $event->space_id)) {
            $computedLocation = $locationService->resolveLocation(
                $branchId,
                $spaceId,
                $room,
                $explicitLocation,
                $locale
            );
            if ($computedLocation !== null) {
                $validated['location'] = $computedLocation;
            }
            if ($branchId !== null) {
                $validated['branch_id'] = $branchId;
            }
        }

        unset($validated['room'], $validated['room_id']);

        $event->update($validated);

        return response()->json(['message' => __('api.admin.event_updated')]);
    }

    /**
     * Retrieve available rooms for event hosting.
     */
    public function rooms(Request $request, EventLocationService $locationService): JsonResponse
    {
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;
        $header = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language')));
        $locale = str_contains($header, 'en') ? 'en' : 'ar';

        return response()->json([
            'data' => $locationService->availableRooms($branchId, $locale),
        ]);
    }

    /**
     * Retrieve all possible event statuses with localized titles.
     */
    public function statuses(\App\Domain\Experience\Services\EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->statuses(),
        ]);
    }
}

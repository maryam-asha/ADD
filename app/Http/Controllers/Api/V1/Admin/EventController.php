<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Domain\Experience\Services\EventLocationService;
use App\Domain\Experience\Services\EventSchedule;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
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

    public function __construct(private EventSchedule $schedule) {}

    public function store(StoreEventRequest $request): EventResource
    {
        $validated = $this->resolvePlacement($request->validated());
        $sessions = $this->takeSessions($validated);

        // start_at/end_at are NOT NULL and are derived from the schedule, so
        // a payload carrying only sessions needs the envelope seeded before
        // the row exists. EventSchedule recomputes it immediately after.
        if ($sessions !== null && ! isset($validated['start_at'])) {
            $validated['start_at'] = collect($sessions)->min('start_at');
            $validated['end_at'] = collect($sessions)->max('end_at');
        }

        $event = Event::create(array_merge(
            ['order' => 0, 'status' => 'draft'],
            $validated
        ));

        if ($sessions !== null) {
            $this->schedule->replace($event, $sessions);
            // refresh(), not fresh(): the latter returns a different instance
            // that Laravel no longer treats as recently created, which turns
            // the 201 into a 200.
            $event->refresh();
        }

        return new EventResource($event);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $validated = $this->resolvePlacement($request->validated(), $event);
        $sessions = $this->takeSessions($validated);

        $event->update($validated);

        // Absent means "leave the schedule alone"; an empty list means the
        // admin cleared it and the event falls back to its own envelope.
        if ($sessions !== null) {
            $this->schedule->replace($event, $sessions);
        }

        return response()->json(['message' => __('api.admin.event_updated')]);
    }

    /**
     * Pull the schedule out of the validated payload.
     *
     * `sessions` is not a column, and start_at/end_at are derived from it
     * rather than stored alongside it, so it must not reach the model.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>|null
     */
    private function takeSessions(array &$validated): ?array
    {
        if (! array_key_exists('sessions', $validated)) {
            return null;
        }

        $sessions = $validated['sessions'];
        unset($validated['sessions']);

        return is_array($sessions) ? array_values($sessions) : [];
    }

    /**
     * Normalise where an event happens.
     *
     * An event is either on-site (a `space_id`, whose branch and display
     * label are derived at read time) or off-site (a free-text
     * `location`) — the Form Requests keep the two mutually exclusive.
     * `branch_id` is still inferred from the space, because it is a real
     * filter key rather than display text, and `capacity` defaults to
     * the room's.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolvePlacement(array $validated, ?Event $event = null): array
    {
        $room = $validated['room'] ?? null;
        unset($validated['room'], $validated['room_id']);

        $spaceId = array_key_exists('space_id', $validated)
            ? $validated['space_id']
            : $event?->space_id;

        // Moving an existing on-site event off-site: `prohibits` already
        // bars sending both, so an incoming location means the space is
        // being dropped. Without this the stale space_id would keep
        // winning and the new address would never show.
        if (filled($validated['location'] ?? null) && $spaceId) {
            $validated['space_id'] = null;
            $spaceId = null;
        }

        if (! $spaceId) {
            // Legacy free-text room, no space behind it: the branch is
            // real but the room is just a label, so the pair is frozen
            // into `location` like any other off-site venue.
            if (filled($room) && blank($validated['location'] ?? null)) {
                $branch = Branch::find($validated['branch_id'] ?? null);

                $validated['location'] = implode(' – ', array_filter([
                    $branch?->translate('name'),
                    $this->humaniseRoom($room),
                ]));
            }

            return $validated;
        }

        // On-site: the label is derived on read, so nothing is stored.
        $validated['location'] = null;

        $space = Space::with('building')->find($spaceId);

        if ($space && empty($validated['capacity']) && ! $event?->capacity && $space->capacity) {
            $validated['capacity'] = (int) $space->capacity;
        }

        if (empty($validated['branch_id']) && $space?->building?->branch_id) {
            $validated['branch_id'] = (int) $space->building->branch_id;
        }

        return $validated;
    }

    /**
     * Turn a legacy room slug ("meeting_room") into a display label.
     */
    private function humaniseRoom(string $room): string
    {
        $normalized = strtolower(trim(str_replace(['-', '_'], ' ', $room)));

        if (str_contains($normalized, 'event')) {
            return __('api.space.types.event_hall');
        }

        if (str_contains($normalized, 'meeting') || $normalized === 'room') {
            return __('api.space.types.room');
        }

        return trim($room);
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
    public function statuses(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->statuses(),
        ]);
    }
}

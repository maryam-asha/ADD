<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Experience\RegisterForEventRequest;
use App\Http\Requests\Public\BrowseEventsRequest;
use App\Http\Resources\Experience\EventRegistrationFieldResource;
use App\Services\Experience\EventRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * Browse events for member app with custom filters (free, cancelled, category, search).
     */
    public function index(BrowseEventsRequest $request, EventBrowseService $service): JsonResponse
    {
        $user = $request->user();
        $result = $service->browse($request->validated(), $user);

        return response()->json($result);
    }

    /**
     * Retrieve event categories separately for the member app.
     */
    public function categories(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->categories(),
        ]);
    }

    /**
     * Retrieve event statuses list for filtering in member app.
     */
    public function statuses(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->statuses(),
        ]);
    }

    /**
     * Display details of a specific event for member app.
     */
    public function show(Request $request, int|string $id, EventBrowseService $service): JsonResponse
    {
        $user = $request->user();
        $details = $service->details($id, $user);

        return response()->json([
            'data' => $details,
        ]);
    }

    /**
     * Retrieve events that the authenticated member is registered for ("My Events").
     */
    public function myEvents(Request $request, EventBrowseService $service): JsonResponse
    {
        $user = $request->user();
        $result = $service->myEvents($user, $request->all());

        return response()->json($result);
    }

    /**
     * Register the authenticated member for an event.
     */
    public function register(
        RegisterForEventRequest $request,
        int|string $id,
        EventRegistrationService $registrationService,
    ): JsonResponse {
        $event = Event::findOrFail($id);

        $result = $registrationService->register($event, $request->user(), $request->fieldValues());

        return response()->json(['data' => $result], 201);
    }

    /**
     * The form a member fills in to register.
     *
     * Always 200s, with an empty `fields` list when the event asks nothing,
     * so the app has a single code path. Identity is returned separately
     * and read-only: it comes from the account, and no form field may
     * redefine it.
     */
    public function registrationForm(Request $request, int|string $id): JsonResponse
    {
        $event = Event::with('activeRegistrationFields')->findOrFail($id);
        $user = $request->user();

        return response()->json([
            'data' => [
                'event_id' => $event->id,
                'requires_approval' => (bool) $event->requires_approval,
                'has_registration_form' => (bool) $event->has_registration_form,
                'external_form_url' => $event->external_form_url,
                'identity' => $user ? [
                    'read_only' => true,
                    'fields' => [
                        ['key' => 'name', 'label' => __('api.event.identity_name'), 'value' => $user->name],
                        ['key' => 'phone', 'label' => __('api.event.identity_phone'), 'value' => $user->phone],
                        ['key' => 'email', 'label' => __('api.event.identity_email'), 'value' => $user->email],
                    ],
                ] : null,
                'fields' => EventRegistrationFieldResource::collection($event->activeRegistrationFields),
            ],
        ]);
    }
}

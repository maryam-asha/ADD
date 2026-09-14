<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BrowseEventsRequest;
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
    public function register(Request $request, int|string $id, EventRegistrationService $registrationService): JsonResponse
    {
        $event = Event::findOrFail($id);
        $user = $request->user();

        $result = $registrationService->register($event, $user);

        return response()->json(['data' => $result], 201);
    }
}

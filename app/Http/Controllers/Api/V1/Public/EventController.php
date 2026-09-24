<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BrowseEventsRequest;
use App\Http\Resources\Experience\EventRegistrationFieldResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(BrowseEventsRequest $request, EventBrowseService $service): JsonResponse
    {
        $user = $request->user('sanctum');
        $result = $service->browse($request->validated(), $user);

        return response()->json($result);
    }

    /**
     * Retrieve event categories separately for the public app.
     */
    public function categories(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->categories(),
        ]);
    }

    /**
     * Retrieve event statuses list for filtering in public app.
     */
    public function statuses(EventBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->statuses(),
        ]);
    }

    public function show(Request $request, int|string $id, EventBrowseService $service): JsonResponse
    {
        $user = $request->user('sanctum');
        $details = $service->details($id, $user);

        return response()->json([
            'data' => $details,
        ]);
    }

    /**
     * The questions an event asks, for the marketing site.
     *
     * No identity block here: a visitor has no account to prefill from.
     */
    public function registrationForm(int|string $id): JsonResponse
    {
        $event = Event::with('activeRegistrationFields')
            ->where('status', 'published')
            ->findOrFail($id);

        if ($event->registersExternally()) {
            return response()->json([
                'data' => [
                    'external_form_url' => $event->external_form_url,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'external_form_url' => null,
                'registration_form' => EventRegistrationFieldResource::collection($event->activeRegistrationFields),
            ],
        ]);
    }
}

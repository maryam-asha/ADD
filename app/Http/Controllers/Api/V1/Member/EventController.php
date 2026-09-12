<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Experience\Services\EventBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BrowseEventsRequest;
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
}

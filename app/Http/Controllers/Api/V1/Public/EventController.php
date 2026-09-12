<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Experience\Services\EventBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\BrowseEventsRequest;
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

    public function show(Request $request, int|string $id, EventBrowseService $service): JsonResponse
    {
        $user = $request->user('sanctum');
        $details = $service->details($id, $user);

        return response()->json([
            'data' => $details,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\SpaceBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\BrowseSpacesRequest;
use Illuminate\Http\JsonResponse;

class SpaceController extends Controller
{
    public function index(BrowseSpacesRequest $request, SpaceBrowseService $service): JsonResponse
    {
        $result = $service->browse($request->validated(), $request->user());

        return response()->json([
            'working_hours_banner' => $result['working_hours_banner'],
            'data' => $result['spaces'],
            'links' => $result['links'],
            'meta' => $result['meta'],
        ]);
    }

    public function show(Space $space, SpaceBrowseService $service): JsonResponse
    {
        $details = $service->details($space, request()->user());

        return response()->json([
            'data' => $details,
        ]);
    }
}

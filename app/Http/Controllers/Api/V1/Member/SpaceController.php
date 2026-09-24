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

        // `data` is the spaces list itself, like every other member listing, so a
        // client can iterate it without unwrapping a named key first. The banner
        // describes the branch those rows belong to rather than paginating them,
        // so it sits alongside `data` instead of inside it or inside `meta`.
        return response()->json([
            'working_hours_banner' => $result['working_hours_banner'],
            'data' => $result['spaces'],
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

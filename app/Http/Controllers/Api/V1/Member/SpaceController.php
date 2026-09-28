<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Services\AvailabilityBrowseService;
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

        // One `data` object, like the space details and the day's slots: the
        // branch's banner, the list as `spaces`, and the paging `meta`. The
        // banner describes the branch those rows belong to rather than
        // paginating them, so it sits beside `spaces`, not inside `meta`.
        return response()->json([
            'data' => [
                'working_hours_banner' => $result['working_hours_banner'],
                'spaces' => $result['spaces'],
                'meta' => $result['meta'],
            ],
        ]);
    }

    /**
     * The space, plus `booking`: the setup its booking calendar starts from
     * (rules, and the upcoming closures as date-times). Sent once here so
     * the slots endpoint, asked again on every date change, can carry only
     * what changes. The branch's
     * week comes once with the spaces list, not here.
     *
     * Composed here rather than inside SpaceBrowseService: the setup belongs
     * to the Booking domain, which already depends on Foundation, and
     * Foundation reaching back would make the two depend on each other.
     */
    public function show(Space $space, SpaceBrowseService $service, AvailabilityBrowseService $availability): JsonResponse
    {
        // details() 404s a non-active space before any booking setup is built.
        $details = $service->details($space, request()->user());

        return response()->json([
            'data' => [
                ...$details,
                'booking' => $availability->bookingSetup($space, app()->getLocale()),
            ],
        ]);
    }
}

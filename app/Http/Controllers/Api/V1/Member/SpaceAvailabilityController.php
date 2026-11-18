<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Services\AvailabilityBrowseService;
use App\Domain\Foundation\Models\Space;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\AvailabilityDayRequest;
use Illuminate\Http\JsonResponse;

/**
 * Read-only. Nothing here holds a slot: a member who lingers on the
 * confirmation screen can still lose one, which is why the write path
 * re-checks under a row lock rather than trusting anything returned here.
 *
 * The locale comes from app()->getLocale(), which SetLocaleFromHeader has
 * already resolved from the `lang` header.
 */
class SpaceAvailabilityController extends Controller
{
    /**
     * Only what changes with the date, in one object:
     * `{data: {date, state, closed_banner, slots, counts, meta}}` — the very
     * body the desk's Admin\BookingCalendarController::slots() returns.
     *
     * The slots are the space's own cells (`meta.granularity_minutes` each).
     * There is no duration: the member picks a start cell and an end cell,
     * at least the rules' `min_duration_minutes` long with no ceiling, and
     * the price is the space's `hourly_rate_points` from the details times
     * that length. The POST re-checks the whole window, so a run of cells
     * that cannot be booked together is refused there with its reason.
     *
     * The booking setup — rules and closures from the space details
     * (`data.booking`), the branch's week from the spaces list — is not
     * repeated on every call: the member app reads it once and re-asks this
     * endpoint only as the date changes.
     */
    public function day(
        AvailabilityDayRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json(['data' => $availability->day(
            $space,
            $request->validated('date'),
            app()->getLocale(),
        )]);
    }
}

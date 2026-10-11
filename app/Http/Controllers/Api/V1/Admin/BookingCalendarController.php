<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Services\AvailabilityBrowseService;
use App\Domain\Booking\Services\BookingCalendarService;
use App\Domain\Foundation\Models\Space;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Booking\CalendarRequest;
use App\Http\Requests\Member\Booking\AvailabilityDayRequest;
use Illuminate\Http\JsonResponse;

/**
 * The operations calendar: spaces as rows, days as cells, occupancy per
 * cell. Read-only — booking directly from it goes through
 * Reception\BookingReceptionController::store(), which runs the same
 * availability rules as the member endpoint.
 */
class BookingCalendarController extends Controller
{
    public function index(CalendarRequest $request, BookingCalendarService $calendar): JsonResponse
    {
        return response()->json($calendar->build($request->validated(), app()->getLocale()));
    }

    /**
     * The same calendar reduced to its occupancy figures — what a
     * dashboard tile needs without the per-booking detail.
     */
    public function occupancy(CalendarRequest $request, BookingCalendarService $calendar): JsonResponse
    {
        $built = $calendar->build($request->validated(), app()->getLocale());

        return response()->json([
            'view' => $built['view'],
            'range' => $built['range'],
            'totals' => $built['totals'],
            'data' => array_map(fn (array $resource) => [
                'space' => $resource['space'],
                'days' => array_map(fn (array $day) => [
                    'date' => $day['date'],
                    'state' => $day['state'],
                    'occupancy' => $day['occupancy'],
                    'booking_count' => count($day['bookings']),
                ], $resource['days']),
            ], $built['resources']),
            'meta' => $built['meta'],
        ]);
    }

    /**
     * The space's cells for one date — the member app's response exactly,
     * `{data: {date, state, closed_banner, slots, counts, meta}}`, from the
     * same service; SpaceAvailabilityControllerTest asserts the two bodies
     * are identical. The desk picks a run of free cells, first to last, at
     * least `booking_window.min_duration_minutes` long — read once, with the
     * hours and closures, from index(), the way the member app reads its
     * setup once from the space details.
     *
     * Reception books on a member's behalf through the very same
     * BookingCreationService, so the desk must be able to see which windows
     * that service will accept. Without this, the dashboard's booking form
     * could only offer a free-form clock and learn the granularity, the
     * minimum duration, the buffer and the existing overlaps by being
     * refused — one 422 at a time.
     *
     * The Form Request is reused from the member namespace rather than
     * copied: it validates a date, which is not a role-specific question.
     * Re-declaring it here would be a second copy free to drift.
     */
    public function slots(
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

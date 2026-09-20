<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Services\AvailabilityBrowseService;
use App\Domain\Booking\Services\BookingCalendarService;
use App\Domain\Foundation\Models\Space;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Booking\CalendarRequest;
use App\Http\Requests\Member\Booking\AvailabilityQuoteRequest;
use App\Http\Requests\Member\Booking\AvailabilitySlotsRequest;
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
     * The bookable slots for one space on one date — the same payload the
     * member app's time picker reads, and deliberately the same service.
     *
     * Reception books on a member's behalf through the very same
     * BookingCreationService, so the desk must be able to see which windows
     * that service will accept. Without this, the dashboard's booking form
     * could only offer a free-form clock and learn the granularity, the
     * minimum duration, the buffer and the existing overlaps by being
     * refused — one 422 at a time.
     *
     * The Form Requests are reused from the member namespace rather than
     * copied: they validate a date and an instant, which is not a
     * role-specific question. Re-declaring them here would be a second copy
     * free to drift.
     */
    public function slots(
        AvailabilitySlotsRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json($availability->day(
            $space,
            $request->validated('date'),
            app()->getLocale(),
        ));
    }

    /**
     * The duration ladder for a chosen start, with each option priced and
     * flagged available or not. An unavailable option carries the reason
     * rather than being hidden, so the desk can say why 1.5h is refused.
     */
    public function quote(
        AvailabilityQuoteRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json($availability->quote(
            $space,
            $request->validated('start_at'),
            app()->getLocale(),
        ));
    }
}

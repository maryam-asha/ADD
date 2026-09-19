<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Services\BookingCalendarService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Booking\CalendarRequest;
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
}

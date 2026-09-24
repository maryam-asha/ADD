<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Booking\IndexBookingsRequest;
use App\Http\Resources\AdminBookingResource;
use Illuminate\Http\JsonResponse;

/**
 * The general operations view of bookings.
 *
 * Deliberately not an AdminResourceController: bookings are
 * command-shaped (approve, reject, check in, cancel, settle), which the
 * build plan §A.3 keeps out of the two-tier CRUD pattern, and this table
 * grows without bound so it must paginate rather than return every row.
 * The write verbs stay on Reception\BookingReceptionController; this is
 * read-only.
 */
class BookingController extends Controller
{
    public function index(IndexBookingsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $paginator = Booking::query()
            ->with(['space.building.branch', 'user:id,name,phone'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['payment_state'] ?? null, fn ($query, $state) => $query->where('payment_state', $state))
            ->when($filters['space_id'] ?? null, fn ($query, $id) => $query->where('space_id', $id))
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($query, $id) => $query->whereHas(
                'space.building',
                fn ($building) => $building->where('branch_id', $id)
            ))
            // Date filters compare against the window, not created_at: an
            // operator asking "what is booked next week" means the booking's
            // own dates.
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('start_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('start_at', '<=', $to))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->whereHas(
                'user',
                fn ($user) => $user->where('name', 'LIKE', "%{$term}%")->orWhere('phone', 'LIKE', "%{$term}%")
            ))
            ->orderByDesc('start_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => AdminBookingResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Booking $booking, BookingPresentationService $presentation): JsonResponse
    {
        $booking->loadMissing(['space.building.branch', 'space.zone.floor', 'user:id,name,phone']);
        $locale = app()->getLocale();

        return response()->json([
            'data' => [
                ...(new AdminBookingResource($booking))->resolve(),
                // The same blocks the member sees, so operations and the
                // member never disagree about what a booking cost or where
                // its approval stands.
                'when' => $presentation->when($booking->start_at, $booking->end_at),
                'breakdown' => $presentation->breakdown($booking->space, $booking->start_at, $booking->end_at, $locale, booking: $booking),
                'request_status' => $presentation->requestStatus($booking, $locale),
                'actions' => $presentation->actions($booking, $locale),
            ],
        ]);
    }
}

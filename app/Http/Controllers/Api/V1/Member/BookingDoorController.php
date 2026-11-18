<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Services\BookingDoorService;
use App\Domain\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The door buttons on a checked-in room booking (BookingDoorService): open
 * or lock the booked room's door without scanning its sticker. Nothing but
 * a message comes back — no lock or vendor detail reaches the member
 * (LockCredentialsNeverReachMemberTest).
 */
class BookingDoorController extends Controller
{
    public function unlock(Request $request, Booking $booking, BookingDoorService $doors): JsonResponse
    {
        return $this->act($request, $booking, fn () => $doors->unlock($request->user(), $booking), 'api.access.unlocked');
    }

    public function lock(Request $request, Booking $booking, BookingDoorService $doors): JsonResponse
    {
        return $this->act($request, $booking, fn () => $doors->lock($request->user(), $booking), 'api.access.locked');
    }

    private function act(Request $request, Booking $booking, callable $action, string $doneKey): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            abort(403, __('api.booking.not_your_booking'));
        }

        try {
            $action();
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __($doneKey)]);
    }
}

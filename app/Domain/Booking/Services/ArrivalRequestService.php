<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;

/**
 * "I'm here" for reception (docs/decisions/kiosk-display.md): a kiosk scan
 * by a member whose booking is reception's to check in. At most one pending
 * request per member; wasRecentlyCreated tells the caller whether this one
 * is new.
 */
class ArrivalRequestService
{
    public function request(User $member, ?Booking $matchedBooking): ArrivalRequest
    {
        $existing = ArrivalRequest::query()
            ->where('user_id', $member->id)
            ->where('status', ArrivalRequestStatus::Pending)
            ->first();

        return $existing ?? ArrivalRequest::create([
            'user_id' => $member->id,
            // Set here, not left to the column default, so the response
            // carries it.
            'status' => ArrivalRequestStatus::Pending,
            'requested_at' => now(),
            'matched_booking_id' => $matchedBooking?->id,
        ]);
    }
}

<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\ArrivalRequest;

/**
 * The member's kiosk scan was filed and waits for reception: their app's
 * other screens — home above all — re-fetch and show the wait. The scanning
 * screen already has it from POST member/scan.
 */
class CoworkingStartRequested extends MemberUpdate
{
    public readonly int $arrivalRequestId;

    /** As the home card names it: `booking` when the scan checks a booking in, else `walkin`. */
    public readonly string $sessionType;

    public readonly ?int $spaceId;

    public function __construct(ArrivalRequest $request)
    {
        parent::__construct((int) $request->user_id);

        $this->arrivalRequestId = $request->id;
        $this->sessionType = $request->matched_booking_id !== null ? 'booking' : 'walkin';
        $this->spaceId = $request->space_id === null ? null : (int) $request->space_id;
    }

    public function broadcastAs(): string
    {
        return 'coworking.start_requested';
    }

    public function broadcastWith(): array
    {
        return [
            'arrival_request_id' => $this->arrivalRequestId,
            'session_type' => $this->sessionType,
            'space_id' => $this->spaceId,
        ];
    }
}

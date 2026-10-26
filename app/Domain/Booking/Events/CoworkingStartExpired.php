<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\ArrivalRequest;

/**
 * Nobody at the desk answered the member's coworking scan in time
 * (kiosk:expire-stale-arrival-requests): nothing started, and the app
 * should stop showing "waiting for reception".
 */
class CoworkingStartExpired extends MemberUpdate
{
    public readonly int $arrivalRequestId;

    public function __construct(ArrivalRequest $request)
    {
        parent::__construct((int) $request->user_id);

        $this->arrivalRequestId = $request->id;
    }

    public function broadcastAs(): string
    {
        return 'coworking.start_expired';
    }

    public function broadcastWith(): array
    {
        return ['arrival_request_id' => $this->arrivalRequestId];
    }
}

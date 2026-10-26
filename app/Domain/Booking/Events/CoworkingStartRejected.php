<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\ArrivalRequest;

/** Reception refused the member's coworking scan: nothing started. */
class CoworkingStartRejected extends MemberUpdate
{
    public readonly int $arrivalRequestId;

    public function __construct(ArrivalRequest $request)
    {
        parent::__construct((int) $request->user_id);

        $this->arrivalRequestId = $request->id;
    }

    public function broadcastAs(): string
    {
        return 'coworking.start_rejected';
    }

    public function broadcastWith(): array
    {
        return ['arrival_request_id' => $this->arrivalRequestId];
    }
}

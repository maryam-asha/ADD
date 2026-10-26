<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;

/** A member scanned the kiosk and a request now waits for the desk, either purpose. */
class ArrivalRequestCreated extends ReceptionUpdate
{
    public readonly int $arrivalRequestId;

    public readonly string $purpose;

    public function __construct(ArrivalRequest $request)
    {
        $this->arrivalRequestId = $request->id;
        $this->purpose = $request->purpose->value;
    }

    public function broadcastAs(): string
    {
        return 'arrival_request.created';
    }

    public function broadcastWith(): array
    {
        return [
            'arrival_request_id' => $this->arrivalRequestId,
            'purpose' => $this->purpose,
            'status' => ArrivalRequestStatus::Pending->value,
        ];
    }
}

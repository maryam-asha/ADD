<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;

/**
 * A pending arrival request left the desk's queue: confirmed, rejected, or
 * expired by the sweep. The status is passed rather than read off the
 * model, since the sweep changes it with a query, not a save.
 */
class ArrivalRequestResolved extends ReceptionUpdate
{
    public readonly int $arrivalRequestId;

    public readonly string $purpose;

    public readonly string $status;

    public function __construct(ArrivalRequest $request, ArrivalRequestStatus $status)
    {
        $this->arrivalRequestId = $request->id;
        $this->purpose = $request->purpose->value;
        $this->status = $status->value;
    }

    public function broadcastAs(): string
    {
        return 'arrival_request.resolved';
    }

    public function broadcastWith(): array
    {
        return [
            'arrival_request_id' => $this->arrivalRequestId,
            'purpose' => $this->purpose,
            'status' => $this->status,
        ];
    }
}

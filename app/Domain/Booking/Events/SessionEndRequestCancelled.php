<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\SessionEndRequest;

/** The member withdrew their waiting end request. */
class SessionEndRequestCancelled extends ReceptionUpdate
{
    public readonly int $sessionEndRequestId;

    public readonly string $sessionType;

    public readonly int $sessionId;

    public readonly string $status;

    public function __construct(SessionEndRequest $request)
    {
        $this->sessionEndRequestId = $request->id;
        // `booking` or `walkin`: the {type} segment of the session URLs.
        $this->sessionType = $request->sessionable->coworkingType();
        $this->sessionId = (int) $request->sessionable_id;
        $this->status = SessionEndRequestStatus::Cancelled->value;
    }

    public function broadcastAs(): string
    {
        return 'session_end_request.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'session_end_request_id' => $this->sessionEndRequestId,
            'session_type' => $this->sessionType,
            'session_id' => $this->sessionId,
            'status' => $this->status,
        ];
    }
}

<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;

/** Reception confirmed the member's coworking scan: the session is running. */
class CoworkingStartConfirmed extends MemberUpdate
{
    public readonly int $arrivalRequestId;

    public readonly string $sessionType;

    public readonly int $sessionId;

    public function __construct(ArrivalRequest $request, Booking|WalkinSession $session)
    {
        parent::__construct((int) $request->user_id);

        $this->arrivalRequestId = $request->id;
        $this->sessionType = $session->coworkingType();
        $this->sessionId = (int) $session->id;
    }

    public function broadcastAs(): string
    {
        return 'coworking.start_confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'arrival_request_id' => $this->arrivalRequestId,
            'session_type' => $this->sessionType,
            'session_id' => $this->sessionId,
        ];
    }
}

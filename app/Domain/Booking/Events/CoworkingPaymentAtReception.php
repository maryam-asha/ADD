<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;

/**
 * A member chose to pay a closed session in cash: the desk collects it and
 * settles the session through its settle-payment endpoint.
 */
class CoworkingPaymentAtReception extends ReceptionUpdate
{
    public readonly string $sessionType;

    public readonly int $sessionId;

    public readonly int $memberId;

    public function __construct(Booking|WalkinSession $session)
    {
        // `booking` or `walkin`: the {type} segment of the session URLs.
        $this->sessionType = $session->coworkingType();
        $this->sessionId = (int) $session->id;
        $this->memberId = (int) $session->user_id;
    }

    public function broadcastAs(): string
    {
        return 'coworking.pay_at_reception';
    }

    public function broadcastWith(): array
    {
        return [
            'session_type' => $this->sessionType,
            'session_id' => $this->sessionId,
            'member_id' => $this->memberId,
        ];
    }
}

<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;

/**
 * A closed session is paid — from the wallet, by card, or in cash at the
 * desk: it leaves home's `unpaid` list, so the app re-fetches GET
 * member/home.
 */
class CoworkingSessionPaid extends MemberUpdate
{
    public readonly string $sessionType;

    public readonly int $sessionId;

    public function __construct(Booking|WalkinSession $session)
    {
        parent::__construct((int) $session->user_id);

        $this->sessionType = $session->coworkingType();
        $this->sessionId = (int) $session->id;
    }

    public function broadcastAs(): string
    {
        return 'coworking.session_paid';
    }

    public function broadcastWith(): array
    {
        return [
            'session_type' => $this->sessionType,
            'session_id' => $this->sessionId,
        ];
    }
}

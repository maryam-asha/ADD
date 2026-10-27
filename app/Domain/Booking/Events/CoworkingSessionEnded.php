<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\SessionEndRequest;

/**
 * Reception confirmed the member's end request: the session is closed and
 * billed. `unpaid` is the app's cue to show the payment methods and pay
 * through POST member/coworking-sessions/{type}/{id}/pay.
 */
class CoworkingSessionEnded extends MemberUpdate
{
    public readonly int $sessionEndRequestId;

    public readonly string $sessionType;

    public readonly int $sessionId;

    public readonly ?string $paymentState;

    public function __construct(SessionEndRequest $request)
    {
        parent::__construct((int) $request->user_id);

        $this->sessionEndRequestId = $request->id;
        $this->sessionType = $request->sessionable->coworkingType();
        $this->sessionId = (int) $request->sessionable_id;
        $this->paymentState = $request->sessionable->payment_state?->value;
    }

    public function broadcastAs(): string
    {
        return 'coworking.session_ended';
    }

    public function broadcastWith(): array
    {
        return [
            'session_end_request_id' => $this->sessionEndRequestId,
            'session_type' => $this->sessionType,
            'session_id' => $this->sessionId,
            'payment_state' => $this->paymentState,
        ];
    }
}

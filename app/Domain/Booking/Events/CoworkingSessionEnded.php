<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Kitchen\Services\KitchenOrderSettlement;

/**
 * Reception confirmed the member's end request: the session is closed and
 * billed. `unpaid` is the app's cue to show the payment methods and pay
 * through POST member/coworking-sessions/{type}/{id}/pay.
 *
 * A session paid or covered by its plan whose accepted kitchen orders are
 * unpaid is `unpaid` too: its card stays awaiting_payment until they are
 * paid (kitchen orders spec, "The bill"), and paying it pays them.
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
        $session = $request->sessionable;
        $this->paymentState = $session->payment_state === PaymentState::Paid && app(KitchenOrderSettlement::class)->owesFor($session)
            ? PaymentState::Unpaid->value
            : $session->payment_state?->value;
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

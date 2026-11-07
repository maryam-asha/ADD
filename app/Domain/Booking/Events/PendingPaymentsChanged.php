<?php

namespace App\Domain\Booking\Events;

/**
 * What the desk has to collect changed — something became owed (a session
 * closed unpaid, a booking or registration to be paid at the desk) or stopped
 * being owed (paid by any method, cancelled, a cash hold lapsed): the
 * pending-payments list and the desk's count re-fetch
 * (PendingPaymentsWatcher). Only whose it is travels.
 */
class PendingPaymentsChanged extends ReceptionUpdate
{
    public function __construct(public readonly ?int $memberId) {}

    public function broadcastAs(): string
    {
        return 'payments.pending_changed';
    }

    public function broadcastWith(): array
    {
        return ['member_id' => $this->memberId];
    }
}

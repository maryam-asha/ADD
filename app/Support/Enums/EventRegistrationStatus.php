<?php

namespace App\Support\Enums;

/**
 * Where a member's event registration stands.
 *
 * Lives under App\Support rather than in Experience or Ecosystem because
 * both sides need it: EventAttendee (Ecosystem) casts to it and
 * EventBrowseService (Experience) counts by it, and
 * tests/Guards/DomainLayerBoundaryTest bans those two domains from
 * importing each other. That guard scans app/Domain only.
 *
 * Pending holds a seat. Points are debited on approval, so a pending
 * registration costs the member nothing — without the hold, a paid event
 * could be flooded past capacity by people who never pay.
 */
enum EventRegistrationStatus: string
{
    // Made with a prepaid method, held for its payment until `payment_due_at`:
    // it holds the seat and can be paid or cancelled, but it is not approved
    // (docs/decisions/prepayment-all-or-nothing.md).
    case AwaitingPayment = 'awaiting_payment';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<self> */
    public static function seatHolding(): array
    {
        return [self::AwaitingPayment, self::Pending, self::Approved];
    }

    /** @return list<string> */
    public static function seatHoldingValues(): array
    {
        return array_map(static fn (self $s) => $s->value, self::seatHolding());
    }

    public function holdsSeat(): bool
    {
        return $this !== self::Rejected;
    }

    /** Whether a decision has been made and the row is no longer actionable. */
    public function isTerminal(): bool
    {
        return ! in_array($this, [self::AwaitingPayment, self::Pending], true);
    }
}

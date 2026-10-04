<?php

namespace App\Domain\Booking\Enums;

/**
 * pending → confirmed → completed (checked out) | no_show (ended, never
 * checked in); pending → rejected (by staff, or lapsed at its start); either
 * open status → cancelled. The last four are final
 * (docs/decisions/booking-outcomes-and-extension.md).
 */
enum BookingStatus: string
{
    // In lifecycle order: the order the app's status catalog lists them in.
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(?string $locale = null): string
    {
        return __('api.booking.statuses.'.$this->value, [], $locale);
    }

    /** Still ahead of a decision or an outcome: the only statuses that may be cancelled. */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** Still to happen: awaiting approval, or confirmed. */
    public static function open(): array
    {
        return [self::Pending, self::Confirmed];
    }

    /**
     * The statuses that hold (or held) a slot. A `pending` booking is itself
     * the hold; `completed` and `no_show` kept theirs to the end, so the
     * calendar still draws them and an early checkout frees nothing.
     *
     * @return list<self>
     */
    public static function slotHolding(): array
    {
        return [self::Pending, self::Confirmed, self::Completed, self::NoShow];
    }

    /**
     * The statuses a payment may still be taken for. A booking that never
     * happened — cancelled, rejected, or one nobody came to — owes nothing.
     *
     * @return list<self>
     */
    public static function payable(): array
    {
        return [self::Pending, self::Confirmed, self::Completed];
    }

    public function isPayable(): bool
    {
        return in_array($this, self::payable(), true);
    }
}

<?php

namespace App\Domain\Booking\Enums;

/**
 * Why a slot cannot be booked — or that it can.
 *
 * This is a presentation-and-reasoning enum, not a column: no migration
 * writes it, so it is deliberately absent from
 * tests/Guards/EnumColumnsHaveBackedEnumCastsTest's concern. The engine
 * judges a cell in all these states; what the member app and the desk are
 * sent is folded to available | booked (forMember()), and a refused window
 * is explained by the POST's own 422
 * (docs/decisions/booking-availability-and-calendar.md).
 */
enum SlotState: string
{
    case Available = 'available';
    case Booked = 'booked';
    case OutsideHours = 'outside_hours';
    case Buffer = 'buffer';
    case AtCapacity = 'at_capacity';
    case Past = 'past';
    case TooSoon = 'too_soon';
    case TooFarAhead = 'too_far_ahead';
    // A room lent to co-working for this stretch: its seats are in the
    // branch's pool, so it takes no room booking meanwhile.
    case CoworkingMode = 'coworking_mode';

    public function isBookable(): bool
    {
        return $this === self::Available;
    }

    /**
     * Whether the time to book this slot has gone: it has started, or it is
     * inside the minimum notice. Waiting never brings it back, so neither the
     * member app nor the desk is sent it (AvailabilityBrowseService::day()).
     */
    public function isGone(): bool
    {
        return $this === self::Past || $this === self::TooSoon;
    }

    /**
     * The state as the member app shows it: one of three.
     *
     * The desk keeps all eight — "every seat is taken" and "one booking holds
     * the room" are different facts to an operator — but to a member a slot
     * is bookable, taken, or its time to be booked has gone. `too_soon` is the
     * last kind: like a slot that has started, waiting never brings it back.
     */
    public function forMember(): self
    {
        return match ($this) {
            self::Available => self::Available,
            self::Past, self::TooSoon => self::Past,
            self::Booked, self::AtCapacity, self::Buffer, self::OutsideHours, self::TooFarAhead, self::CoworkingMode => self::Booked,
        };
    }

    /**
     * The states a member slot is sent with, in the order `counts` lists them.
     * `past` is not one: those slots are left out of the member list.
     *
     * @return list<self>
     */
    public static function memberStates(): array
    {
        return [self::Available, self::Booked];
    }
}

<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CurrentBookingLocator;
use App\Domain\Booking\Services\OpenCoworkingSessionLocator;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\QrPointPlace;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\QrPoint;

/**
 * Which bill a kitchen order joins (spec "Which bill an order joins"):
 *
 * 1. the QR names a space the member holds a checked-in booking on → that booking;
 * 2. otherwise the bill they chose, when it is one of eligibleBills();
 * 3. otherwise their open coworking session;
 * 4. otherwise their one checked-in room, office or hall booking.
 *
 * Several bookings and no valid choice is asked of them (422). No session
 * or booking at all is no bill (null): the order is paid when it is sent
 * (2026-11-14 spec, decision 2).
 *
 * Only the member's own sessions and bookings: who else on a booking may
 * order onto it is deferred (spec, "Who on a booking may order").
 */
class BillLocator
{
    public function __construct(
        private readonly OpenCoworkingSessionLocator $openSessions,
        private readonly CurrentBookingLocator $currentBookings,
    ) {}

    /**
     * Every bill the member could order onto now: the open coworking session
     * first, then the checked-in bookings, earliest check-in first.
     *
     * @return list<Booking|WalkinSession>
     */
    public function eligibleBills(User $member): array
    {
        $open = $this->openSessions->for($member);

        return [...($open === null ? [] : [$open]), ...$this->checkedInBookings($member)];
    }

    /**
     * @param  array{type: string, id: int|string}|null  $chosen  the bill the member picked, by the API's coworking names ('booking' | 'walkin')
     *
     * @throws KitchenOrderException several and no valid choice (422)
     */
    public function resolve(User $member, ?QrPoint $qr, ?array $chosen): Booking|WalkinSession|null
    {
        $open = $this->openSessions->for($member);
        $bookings = $this->checkedInBookings($member);

        if ($open === null && $bookings === []) {
            return null;
        }

        if ($qr?->place_type === QrPointPlace::Space) {
            foreach ($bookings as $booking) {
                if ((int) $booking->space_id === (int) $qr->place_id) {
                    return $booking;
                }
            }
        }

        if ($chosen !== null) {
            foreach ($this->eligibleBills($member) as $bill) {
                if ($chosen['type'] === $bill->coworkingType() && (int) $chosen['id'] === $bill->id) {
                    return $bill;
                }
            }
        }

        if ($open !== null) {
            return $open;
        }

        if (count($bookings) === 1) {
            return $bookings[0];
        }

        throw new KitchenOrderException('api.kitchen.choose_bill', 422);
    }

    /** @return list<Booking> the room, office and hall bookings checked in and not closed */
    private function checkedInBookings(User $member): array
    {
        $bookings = [];

        foreach ($this->currentBookings->for($member) as [$state, $booking]) {
            if ($state === 'active') {
                $bookings[] = $booking;
            }
        }

        return $bookings;
    }
}

<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The room, office or hall bookings the member's home cards show
 * (`active_booking`, a list — a member can hold more than one at a time),
 * each in its state, in this order:
 *
 * - `active`: checked in (reception or the door QR) and not closed yet,
 *   earliest check-in first;
 * - `ready`: its check-in window has opened
 *   (BookingCheckInService::EARLY_CHECK_IN_MINUTES before its start) and it
 *   has not ended, soonest start first.
 *
 * Once closed, a booking still owing is a card in state awaiting_payment
 * (MemberHomeService, from OutstandingItems::closedFor), not located here.
 * Coworking bookings are never here: they are `active_session`, a coworking
 * session like a walk-in.
 */
class CurrentBookingLocator
{
    /** @return list<array{0: string, 1: Booking}> */
    public function for(User $member): array
    {
        $active = $this->rooms($member)
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->oldest('checked_in_at')
            ->oldest('id')
            ->get()
            ->map(fn (Booking $booking) => ['active', $booking]);

        $ready = $this->rooms($member)
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('checked_in_at')
            ->where('start_at', '<=', now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES))
            ->where('end_at', '>', now())
            ->oldest('start_at')
            ->oldest('id')
            ->get()
            ->map(fn (Booking $booking) => ['ready', $booking]);

        return $active->concat($ready)->values()->all();
    }

    /**
     * The state of this one booking's card in `active_booking` right now, by
     * the same rules as the list (for() above, and OutstandingItems::closedFor
     * for one still owing) — null when it has no card there (not open yet,
     * paid, no-show, cancelled, or a coworking booking). Every member event
     * about a booking carries it, so the app updates or drops that booking's
     * card alone instead of guessing from the event's name.
     */
    public function stateOf(Booking $booking): ?string
    {
        if ($booking->isCoworking()) {
            return null;
        }

        if ($booking->checked_out_at !== null) {
            $owes = $booking->status->isPayable()
                && $booking->payment_state === PaymentState::Unpaid
                && (int) $booking->price_points > $booking->pointsPaid();

            return $owes ? 'awaiting_payment' : null;
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            return null;
        }

        if ($booking->checked_in_at !== null) {
            return 'active';
        }

        $windowOpen = $booking->start_at->lte(now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES))
            && $booking->end_at->gt(now());

        return $windowOpen ? 'ready' : null;
    }

    /** @return Builder<Booking> */
    private function rooms(User $member): Builder
    {
        return Booking::query()
            ->where('user_id', $member->id)
            ->whereHas('space', fn (Builder $space) => $space->where('space_type', '!=', SpaceType::CoSpace));
    }
}

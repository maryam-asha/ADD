<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The room, office or hall booking the member's home card shows
 * (`active_booking`), and in which state — the first that applies:
 *
 * - `active`: checked in (reception or the door QR) and not closed yet;
 * - `ready`: its check-in window has opened
 *   (BookingCheckInService::EARLY_CHECK_IN_MINUTES before its start) and it
 *   has not ended — the soonest one.
 *
 * Once closed, a booking still owing is in home's `unpaid` list
 * (OutstandingItems::closedFor), never this card. Coworking bookings are
 * never here: they are `active_session`, a coworking session like a walk-in.
 */
class CurrentBookingLocator
{
    /** @return array{0: string, 1: Booking}|null */
    public function for(User $member): ?array
    {
        $active = $this->rooms($member)
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->oldest('checked_in_at')
            ->first();

        if ($active !== null) {
            return ['active', $active];
        }

        $ready = $this->rooms($member)
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('checked_in_at')
            ->where('start_at', '<=', now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES))
            ->where('end_at', '>', now())
            ->oldest('start_at')
            ->first();

        return $ready === null ? null : ['ready', $ready];
    }

    /** @return Builder<Booking> */
    private function rooms(User $member): Builder
    {
        return Booking::query()
            ->where('user_id', $member->id)
            ->whereHas('space', fn (Builder $space) => $space->where('space_type', '!=', SpaceType::CoSpace));
    }
}

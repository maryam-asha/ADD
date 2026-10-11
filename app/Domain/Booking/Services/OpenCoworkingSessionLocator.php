<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;

/**
 * "The member's open coworking session" — there is at most one
 * (docs/decisions/coworking-self-service-sessions.md), and every member
 * endpoint reads it through here. Its own class so the services that start
 * sessions (WalkInCapacityService, BookingCheckInService) can refuse a second
 * one without depending on CoworkingSessionService, which depends on them.
 */
class OpenCoworkingSessionLocator
{
    /** The member's checked-in, not yet checked-out session on a coworking space, if any. */
    public function for(User $member): Booking|WalkinSession|null
    {
        $onCoworking = fn ($query) => $query->where('space_type', SpaceType::CoSpace);

        return WalkinSession::query()
            ->where('user_id', $member->id)
            ->whereNull('checked_out_at')
            ->whereHas('space', $onCoworking)
            ->latest('checked_in_at')
            ->first()
            ?? Booking::query()
                ->where('user_id', $member->id)
                ->where('status', BookingStatus::Confirmed)
                ->whereNotNull('checked_in_at')
                ->whereNull('checked_out_at')
                ->whereHas('space', $onCoworking)
                ->latest('checked_in_at')
                ->first();
    }
}

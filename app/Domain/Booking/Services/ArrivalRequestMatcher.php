<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;

/**
 * Finds the member's own booking for "today," where "today" is resolved in
 * the branch's local timezone (docs/decisions/kiosk-display.md): the
 * earliest one not yet ended and not yet checked in, however far off it
 * starts.
 *
 * Two callers. For an arrival request the match is informational only —
 * reception's confirm action is what changes booking/session state. For the
 * kiosk scan (docs/decisions/coworking-self-service-sessions.md) it drives
 * the check-in: CoworkingSessionService checks the matched booking in, but
 * only once it starts within CoworkingSessionService::EARLY_CHECK_IN_MINUTES,
 * a rule applied there, not here, so the arrival flow's matching is unchanged.
 */
class ArrivalRequestMatcher
{
    public function matchBookingFor(User $member, Branch $branch, CarbonInterface $now, ?Space $space = null): ?Booking
    {
        $timezone = $branch->timezone;
        $localNow = $now->copy()->setTimezone($timezone);
        $startOfDayUtc = $localNow->copy()->startOfDay()->setTimezone('UTC');
        $endOfDayUtc = $localNow->copy()->endOfDay()->setTimezone('UTC');

        return Booking::query()
            ->where('user_id', $member->id)
            ->blocking()
            ->whereNull('checked_in_at')
            ->whereBetween('start_at', [$startOfDayUtc, $endOfDayUtc])
            ->where('end_at', '>=', $now)
            ->whereHas('space.building', fn ($query) => $query->where('branch_id', $branch->id))
            // The kiosk scan looks only at its own coworking space; the
            // arrival flow keeps matching across the whole branch.
            ->when($space !== null, fn ($query) => $query->where('space_id', $space->id))
            ->orderBy('start_at')
            ->first();
    }
}

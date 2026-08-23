<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;

/**
 * Matches an arrival request to the member's own booking for "today," where
 * "today" is resolved in the branch's local timezone
 * (docs/decisions/kiosk-display.md). This match is informational only — it
 * decides nothing on its own; reception's confirm action is the only place
 * that changes booking/session state.
 */
class ArrivalRequestMatcher
{
    public function __construct(private readonly SettingService $settings) {}

    public function matchBookingFor(User $member, Branch $branch, CarbonInterface $now): ?Booking
    {
        $timezone = $this->settings->get('app.timezone', 'Asia/Damascus');
        $localNow = $now->copy()->setTimezone($timezone);
        $startOfDayUtc = $localNow->copy()->startOfDay()->setTimezone('UTC');
        $endOfDayUtc = $localNow->copy()->endOfDay()->setTimezone('UTC');

        return Booking::query()
            ->where('user_id', $member->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->whereNull('checked_in_at')
            ->whereBetween('start_at', [$startOfDayUtc, $endOfDayUtc])
            ->whereHas('space.building', fn ($query) => $query->where('branch_id', $branch->id))
            ->orderBy('start_at')
            ->first();
    }
}

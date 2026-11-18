<?php

namespace App\Domain\Access\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;

/**
 * When a booked room's door opens to its member from the sticker scan
 * (product owner, 2026-11-08): from the early check-in allowance before the
 * booking starts (BookingCheckInService::earlyWindowMinutesFor()) until
 * `booking.overrun_grace_minutes` after it ends. Rooms and offices only —
 * the shared coworking space has no lock, by a standing decision.
 *
 * The keypad code on the lock keeps the booking's own start and end.
 */
class BookingDoorWindow
{
    public const OVERRUN_GRACE_KEY = 'booking.overrun_grace_minutes';

    public const DEFAULT_OVERRUN_GRACE_MINUTES = 10;

    public function __construct(
        private readonly BookingCheckInService $checkIns,
        private readonly SettingService $settings,
    ) {}

    public function opensAt(Booking $booking): CarbonInterface
    {
        return $booking->start_at->copy()->subMinutes($this->checkIns->earlyWindowMinutesFor($booking));
    }

    public function closesAt(Booking $booking): CarbonInterface
    {
        $grace = max(0, (int) $this->settings->get(self::OVERRUN_GRACE_KEY, self::DEFAULT_OVERRUN_GRACE_MINUTES));

        return $booking->end_at->copy()->addMinutes($grace);
    }

    public function contains(Booking $booking, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $at->greaterThanOrEqualTo($this->opensAt($booking)) && $at->lessThanOrEqualTo($this->closesAt($booking));
    }
}

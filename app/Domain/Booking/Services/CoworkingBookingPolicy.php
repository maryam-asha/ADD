<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\LateArrivalBilling;
use App\Domain\Booking\Models\Booking;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonImmutable;

/**
 * The two global settings a coworking booking's lifecycle follows (product
 * owner, 2026-11-02) — policy, so the same for every branch:
 *
 * - how much of an unpaid booking may pass with its member absent before
 *   the seat is released (UnpaidNoShowReleaser), as a percent of its length;
 * - where an unpaid booking's bill starts when its member is late.
 */
class CoworkingBookingPolicy
{
    public const RELEASE_PERCENT_KEY = 'coworking.no_show_release_percent';

    public const LATE_BILLING_KEY = 'coworking.late_arrival_billing';

    public const DEFAULT_RELEASE_PERCENT = 25;

    public function __construct(private readonly SettingService $settings) {}

    /** 1..100, whatever was stored. */
    public function releasePercent(): int
    {
        return max(1, min(100, (int) $this->settings->get(self::RELEASE_PERCENT_KEY, self::DEFAULT_RELEASE_PERCENT)));
    }

    public function lateArrivalBilling(): LateArrivalBilling
    {
        return LateArrivalBilling::tryFrom((string) $this->settings->get(self::LATE_BILLING_KEY, LateArrivalBilling::Arrival->value))
            ?? LateArrivalBilling::Arrival;
    }

    /** When an unpaid booking nobody came to is released: its start plus that share of its length, to the second. */
    public function releaseAt(Booking $booking): CarbonImmutable
    {
        $length = (int) $booking->start_at->diffInSeconds($booking->end_at);

        return CarbonImmutable::instance($booking->start_at)->utc()
            ->addSeconds((int) ceil($length * $this->releasePercent() / 100));
    }
}

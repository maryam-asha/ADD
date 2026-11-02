<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Membership\Services\PlanDiscountService;

/**
 * What a booking took from its member's plan comes back together once it
 * no longer holds a charge — cancelled, rejected, lapsed or released
 * unpaid: its discount use and its plan hours, to the membership they were
 * spent from, whatever the member holds now (product owner, 2026-11-02).
 */
class BookingPlanBenefits
{
    public function __construct(
        private readonly PlanDiscountService $discounts,
        private readonly PlanAllowanceService $allowances,
    ) {}

    public function voidFor(Booking $booking): void
    {
        $this->discounts->voidFor($booking);
        $this->allowances->releaseFor($booking);
    }
}

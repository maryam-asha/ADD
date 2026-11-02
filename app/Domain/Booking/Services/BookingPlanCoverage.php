<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\BookingCoverage;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanAllowanceService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How much of a booking the member's plan covers, for both booking paths
 * (product owner, 2026-11-02): the plan live at the booking's start, its
 * allowance on the space (its own coworking hours inside the window, or
 * free hours on the space), never past the plan's end. The hours are
 * spent when the booking is made and given back if it stops holding a
 * charge (BookingPlanBenefits).
 */
class BookingPlanCoverage
{
    public function __construct(
        private readonly MembershipResolver $memberships,
        private readonly PlanAllowanceService $allowances,
    ) {}

    public function for(User $member, Space $space, CarbonInterface $start, CarbonInterface $end, int $alreadyReservedSeconds = 0): BookingCoverage
    {
        $membership = $this->memberships->activeFor($member);
        $start = CarbonImmutable::instance($start)->utc();

        if ($membership === null
            || $start->lessThan($membership->current_period_start)
            || $start->greaterThanOrEqualTo($membership->current_period_end)) {
            return BookingCoverage::none();
        }

        $allowance = $this->allowances->allowanceFor($membership, $space->space_type, $space->id);

        if ($allowance === null) {
            return BookingCoverage::none();
        }

        $until = min(CarbonImmutable::instance($end)->utc(), CarbonImmutable::instance($membership->current_period_end)->utc());
        $covered = $this->allowances->coverableSeconds($membership, $member, $space, $start, $until, 0, $alreadyReservedSeconds);
        [$cycleStart] = $this->allowances->cycleFor($membership, $start);

        return new BookingCoverage(
            $membership,
            $allowance,
            $covered,
            $allowance->isCoworkingSeat(),
            ($allowance->row?->id ?? 'coworking').'@'.$cycleStart->toIso8601String(),
        );
    }

    /** Spends the covered hours on the booking just made. */
    public function record(BookingCoverage $coverage, Booking $booking, User $member): void
    {
        if ($coverage->coveredSeconds <= 0 || $coverage->membership === null || $coverage->allowance === null) {
            return;
        }

        $this->allowances->record($coverage->membership, $member, $coverage->allowance, $booking, $coverage->coveredSeconds, $booking->start_at);
    }
}

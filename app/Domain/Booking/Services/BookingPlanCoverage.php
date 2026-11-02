<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\BookingCoverage;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
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

    /**
     * What the booking's plan covers of [$from, $to) at its closure,
     * counting the hours the booking already holds as its own (they come
     * back when it is settled). Spends nothing — the reception quote.
     */
    public function coverableFor(Booking $booking, CarbonInterface $from, CarbonInterface $to, int $blockSeconds): int
    {
        [$membership, $until] = $this->context($booking, $to);

        return $membership === null || $booking->user === null
            ? 0
            : $this->allowances->coverableSeconds($membership, $booking->user, $booking->space, $from, $until, $blockSeconds, -$this->allowances->spentBy($booking));
    }

    /**
     * An unpaid booking is billed for the stretch it used: the hours it took
     * when made are replaced by what that stretch actually used — given
     * back, or spent further. Returns the seconds covered.
     */
    public function settle(Booking $booking, CarbonInterface $from, CarbonInterface $to, int $blockSeconds): int
    {
        $covered = $this->coverableFor($booking, $from, $to, $blockSeconds);
        $this->allowances->releaseFor($booking);
        $this->spend($booking, $covered);

        return $covered;
    }

    /**
     * The plan's cover of a paid booking's overstay [$from, $to): its own
     * window was paid, so only hours left beyond what it already holds
     * count. Spends them when $spend is true. Returns the seconds covered.
     */
    public function coverOverstay(Booking $booking, CarbonInterface $from, CarbonInterface $to, int $blockSeconds, bool $spend): int
    {
        [$membership, $until] = $this->context($booking, $to);

        if ($membership === null || $booking->user === null) {
            return 0;
        }

        $covered = $this->allowances->coverableSeconds($membership, $booking->user, $booking->space, $from, $until, $blockSeconds);

        if ($spend) {
            $this->spend($booking, $covered);
        }

        return $covered;
    }

    /** @return array{0: ?Membership, 1: CarbonImmutable} the member's plan live at the booking's start, and $to clipped to its end */
    private function context(Booking $booking, CarbonInterface $to): array
    {
        $membership = $booking->user !== null ? $this->memberships->activeFor($booking->user) : null;

        if ($membership === null
            || $booking->start_at->lessThan($membership->current_period_start)
            || $booking->start_at->greaterThanOrEqualTo($membership->current_period_end)) {
            return [null, CarbonImmutable::instance($to)->utc()];
        }

        return [$membership, min(CarbonImmutable::instance($to)->utc(), CarbonImmutable::instance($membership->current_period_end)->utc())];
    }

    private function spend(Booking $booking, int $seconds): void
    {
        [$membership] = $this->context($booking, $booking->end_at);
        $allowance = $membership === null ? null : $this->allowances->allowanceFor($membership, $booking->space->space_type, $booking->space_id);

        if ($seconds <= 0 || $allowance === null || $booking->user === null) {
            return;
        }

        $this->allowances->record($membership, $booking->user, $allowance, $booking, $seconds, $booking->start_at);
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

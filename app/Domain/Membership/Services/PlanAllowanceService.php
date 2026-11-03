<?php

namespace App\Domain\Membership\Services;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\PlanSpaceHour;
use App\Domain\Membership\ValueObjects\HourAllowance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one answer to "how much of this stretch does this person's plan
 * cover" (product owner, 2026-11-02): from the plan's own coworking terms
 * (seat, hours, daily window) or a free-hours benefit on the space, counted
 * per person — an employee of a company plan has the full hours to
 * themselves — and per cycle (the whole period, or each month from the
 * subscription's start; nothing rolls over).
 *
 * Hours are spent through record() and given back through releaseFor().
 */
class PlanAllowanceService
{
    /**
     * The cycle containing $at: the whole period, or the month-long slice of
     * it starting on the subscription's day, never past the period's end.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function cycleFor(Membership $membership, CarbonInterface $at): array
    {
        $start = CarbonImmutable::instance($membership->current_period_start)->utc();
        $periodEnd = CarbonImmutable::instance($membership->current_period_end)->utc();

        if ($membership->plan?->allowance_cycle !== AllowanceCycle::Monthly) {
            return [$start, $periodEnd];
        }

        $at = CarbonImmutable::instance($at)->utc();
        $months = 0;

        while ($start->addMonthsNoOverflow($months + 1)->lessThanOrEqualTo($at)) {
            $months++;
        }

        return [$start->addMonthsNoOverflow($months), min($periodEnd, $start->addMonthsNoOverflow($months + 1))];
    }

    /**
     * What the plan gives on a space of $type ($spaceId narrows to one
     * space): its own coworking hours on a coworking space — unlimited for
     * a dedicated desk — else a free-hours benefit on that space, then on
     * its type. Null when it gives none.
     */
    public function allowanceFor(Membership $membership, SpaceType $type, ?int $spaceId = null): ?HourAllowance
    {
        $plan = $membership->plan;

        if ($plan === null) {
            return null;
        }

        if ($type === SpaceType::CoSpace && $plan->includesCoworking()) {
            $hours = $plan->coworking_seat === CoworkingSeat::Dedicated || $plan->coworking_hours === null
                ? null
                : (float) $plan->coworking_hours;

            return new HourAllowance(null, $hours, true);
        }

        $rows = $plan->relationLoaded('spaceHours') ? $plan->spaceHours : $plan->spaceHours()->get();
        $row = ($spaceId !== null ? $rows->firstWhere('space_id', $spaceId) : null)
            ?? $rows->first(fn (PlanSpaceHour $r) => $r->space_id === null && $r->space_type === $type);

        return $row === null ? null : new HourAllowance($row, (float) $row->hours, false);
    }

    /** Seconds $user has left of $allowance in the cycle containing $at; null when unlimited. */
    public function remainingSeconds(Membership $membership, User $user, HourAllowance $allowance, CarbonInterface $at): ?int
    {
        if ($allowance->isUnlimited()) {
            return null;
        }

        return max(0, (int) round($allowance->hours * 3600) - $this->spentSeconds($membership, $user, $allowance, $at));
    }

    /** Seconds $user spent of $allowance in the cycle containing $at — unlimited time included. */
    public function spentSeconds(Membership $membership, User $user, HourAllowance $allowance, CarbonInterface $at): int
    {
        [$start, $end] = $this->cycleFor($membership, $at);

        return (int) PlanHourUsage::query()
            ->where('membership_id', $membership->id)
            ->where('user_id', $user->id)
            ->where('allowance_kind', $allowance->kind())
            ->when($allowance->row !== null, fn ($query) => $query->where('plan_space_hour_id', $allowance->row->id))
            ->where('cycle_start', '>=', $start)
            ->where('cycle_start', '<', $end)
            ->sum('seconds');
    }

    /**
     * How much of [$from, $to) on $space the plan covers for $user: the
     * part inside the daily window for the plan's own coworking hours (all
     * of it with no window), any of it for a free-hours benefit; never more
     * than is left this cycle. With $blockSeconds > 1, the covered time is
     * rounded up to whole blocks and the hours left spent only in whole
     * blocks — a remainder smaller than a block stays. $alreadySpent is
     * time a caller has earmarked but not yet recorded (a batch of
     * bookings priced before any is made).
     */
    public function coverableSeconds(Membership $membership, User $user, Space $space, CarbonInterface $from, CarbonInterface $to, int $blockSeconds = 0, int $alreadySpent = 0): int
    {
        $allowance = $this->allowanceFor($membership, $space->space_type, $space->id);

        if ($allowance === null) {
            return 0;
        }

        $inReach = $allowance->windowed
            ? array_sum(array_map(
                fn (array $segment) => (int) $segment[0]->diffInSeconds($segment[1]),
                $membership->plan->windowSegments($from, $to),
            ))
            : max(0, (int) $from->diffInSeconds($to, false));

        if ($inReach <= 0) {
            return 0;
        }

        $remaining = $this->remainingSeconds($membership, $user, $allowance, $from);
        $remaining = $remaining === null ? null : max(0, $remaining - $alreadySpent);

        if ($blockSeconds > 1) {
            $inReach = (int) (ceil($inReach / $blockSeconds) * $blockSeconds);
            $remaining = $remaining === null ? null : intdiv($remaining, $blockSeconds) * $blockSeconds;
        }

        return $remaining === null ? $inReach : min($inReach, $remaining);
    }

    /**
     * Spends $seconds of $allowance for $user on $usable, against the cycle
     * containing $at. Serialized on the membership row, and re-checked
     * there: a limited allowance is never overdrawn. Unlimited time is
     * recorded too, for the session's report.
     */
    public function record(Membership $membership, User $user, HourAllowance $allowance, Booking|WalkinSession $usable, int $seconds, CarbonInterface $at): ?PlanHourUsage
    {
        if ($seconds <= 0) {
            return null;
        }

        Membership::query()->whereKey($membership->id)->lockForUpdate()->first();

        $remaining = $this->remainingSeconds($membership, $user, $allowance, $at);

        if ($remaining !== null && $seconds > $remaining) {
            throw new ReceptionActionException('api.reception.plan_hours_overdrawn', 409);
        }

        return PlanHourUsage::query()->create([
            'membership_id' => $membership->id,
            'user_id' => $user->id,
            'plan_space_hour_id' => $allowance->row?->id,
            'allowance_kind' => $allowance->kind(),
            'usable_type' => $usable->getMorphClass(),
            'usable_id' => $usable->getKey(),
            'cycle_start' => $this->cycleFor($membership, $at)[0],
            'seconds' => $seconds,
        ]);
    }

    /** Gives back every hour $usable spent; returns the seconds. */
    public function releaseFor(Booking|WalkinSession $usable): int
    {
        $usages = PlanHourUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey());

        $seconds = (int) $usages->sum('seconds');
        $usages->delete();

        return $seconds;
    }

    /** Seconds $usable has spent of its plan's hours. */
    public function spentBy(Booking|WalkinSession $usable): int
    {
        return (int) PlanHourUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->sum('seconds');
    }
}

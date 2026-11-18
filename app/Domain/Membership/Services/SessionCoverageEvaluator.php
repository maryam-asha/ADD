<?php

namespace App\Domain\Membership\Services;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\ValueObjects\SessionCoverage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Answers, before a coworking session starts, whether the member's plan
 * will cover it and why not (docs/decisions/coworking-self-service-sessions.md).
 * Read-only: it spends nothing. What is actually absorbed is still decided
 * at checkout by PlanAllowanceService.
 */
class SessionCoverageEvaluator
{
    public function __construct(
        private readonly MembershipResolver $memberships,
        private readonly PlanAllowanceService $allowances,
    ) {}

    public function evaluate(User $member, CarbonInterface $at): SessionCoverage
    {
        $membership = $this->memberships->activeFor($member);

        if ($membership === null || $membership->plan === null) {
            return SessionCoverage::notCovered(
                $this->memberships->hasHeldAnyFor($member) ? CoverageReason::PlanExpired : CoverageReason::NoPlan
            );
        }

        $plan = $membership->plan;
        $allowance = $this->allowances->allowanceFor($membership, SpaceType::CoSpace);

        // The plan gives nothing on coworking: neither a seat nor hours.
        if ($allowance === null) {
            return SessionCoverage::notCovered(CoverageReason::PlanWithoutHours, $plan);
        }

        $at = CarbonImmutable::instance($at)->utc();
        $remaining = $this->allowances->remainingSeconds($membership, $member, $allowance, $at);

        if ($remaining !== null && $remaining <= 0) {
            return SessionCoverage::notCovered(CoverageReason::HoursExhausted, $plan, $allowance->hours, 0.0);
        }

        [, $cycleEnd] = $this->allowances->cycleFor($membership, $at);
        $until = min($cycleEnd, CarbonImmutable::instance($membership->current_period_end)->utc());

        if ($allowance->windowed && $plan->hasAccessWindow()) {
            $windowEnd = $plan->currentWindowEndsAt($at);

            if ($windowEnd === null) {
                return SessionCoverage::notCovered(CoverageReason::OutsideWindow, $plan, $allowance->hours, $this->hours($remaining));
            }

            $until = min($until, $windowEnd);
        }

        if ($remaining !== null) {
            $until = min($until, $at->addSeconds($remaining));
        }

        return SessionCoverage::covered($plan, $allowance->hours, $this->hours($remaining), $until);
    }

    private function hours(?int $seconds): ?float
    {
        return $seconds === null ? null : round($seconds / 3600, 2);
    }
}

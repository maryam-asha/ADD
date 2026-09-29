<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\ValueObjects\SessionCoverage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Answers, before a coworking session starts, whether the member's plan
 * will cover it and why not (docs/decisions/coworking-self-service-sessions.md).
 * Read-only: it spends nothing. What is actually absorbed is still decided
 * at checkout by PlanHourCoverageService, exactly as before.
 */
class SessionCoverageEvaluator
{
    public function __construct(
        private readonly MembershipResolver $memberships,
        private readonly PlanHourCoverageService $planHours,
        private readonly ResourceQuotaService $quotas,
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
        $grant = $this->planHours->hourGrantFor($membership);

        if ($grant === null) {
            return SessionCoverage::notCovered(CoverageReason::PlanWithoutHours, $plan);
        }

        $total = (float) $grant->quantity;
        $remaining = (float) ($this->quotas->remainingFor($membership, $grant->resourceType) ?? 0);

        if ($remaining <= 0) {
            return SessionCoverage::notCovered(CoverageReason::HoursExhausted, $plan, $total, 0.0);
        }

        $windowEnd = $plan->currentWindowEndsAt($at);

        if ($windowEnd === null) {
            return SessionCoverage::notCovered(CoverageReason::OutsideWindow, $plan, $total, $remaining);
        }

        $hoursRunOut = CarbonImmutable::instance($at)->utc()->addSeconds((int) floor($remaining * 3600));

        return SessionCoverage::covered(
            $plan,
            $total,
            $remaining,
            $windowEnd->lessThan($hoursRunOut) ? $windowEnd : $hoursRunOut,
        );
    }
}

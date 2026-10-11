<?php

namespace App\Domain\Membership\ValueObjects;

use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\Enums\CoverageStatus;
use App\Domain\Membership\Models\Plan;
use Carbon\CarbonImmutable;

/**
 * What a member's plan will do for a coworking session starting now
 * (docs/decisions/coworking-self-service-sessions.md). toArray() is what
 * walkin_sessions.coverage_snapshot stores: taken once at start, so what
 * the member agreed to never drifts afterwards.
 */
final class SessionCoverage
{
    private function __construct(
        public readonly CoverageStatus $status,
        public readonly ?CoverageReason $reason,
        public readonly ?Plan $plan,
        public readonly ?float $hoursTotal,
        public readonly ?float $hoursRemaining,
        public readonly ?CarbonImmutable $coveredUntil,
    ) {}

    public static function covered(Plan $plan, float $hoursTotal, float $hoursRemaining, CarbonImmutable $coveredUntil): self
    {
        return new self(CoverageStatus::Covered, null, $plan, $hoursTotal, $hoursRemaining, $coveredUntil);
    }

    public static function notCovered(CoverageReason $reason, ?Plan $plan = null, ?float $hoursTotal = null, ?float $hoursRemaining = null): self
    {
        return new self(CoverageStatus::NotCovered, $reason, $plan, $hoursTotal, $hoursRemaining, null);
    }

    public function isCovered(): bool
    {
        return $this->status === CoverageStatus::Covered;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            // Both languages kept: an old snapshot still renders in whichever
            // language the reader asks for (SessionCoverageResource picks).
            'plan' => $this->plan === null ? null : ['id' => $this->plan->id, 'name' => $this->plan->name],
            'hours_total' => $this->hoursTotal,
            'hours_remaining' => $this->hoursRemaining,
            'window' => $this->plan?->hasAccessWindow()
                ? ['start' => $this->plan->access_window_start, 'end' => $this->plan->access_window_end]
                : null,
            'covered_until' => $this->coveredUntil?->toIso8601String(),
        ];
    }
}

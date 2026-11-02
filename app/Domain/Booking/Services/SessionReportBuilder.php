<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
use App\Support\Presenters\AppliedDiscountPresenter;
use App\Support\Presenters\PaymentStatePresenter;

/**
 * The end-of-session report (docs/decisions/coworking-self-service-sessions.md):
 * time, then every transaction linked to the session. `lines` is a generic
 * list on purpose — café orders arrive later as kind "order", with no
 * redesign. Reads only; every figure comes from what closure stored.
 */
class SessionReportBuilder
{
    public function __construct(
        private readonly AmountCalculator $amounts,
        private readonly PlanAllowanceService $allowances,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Booking|WalkinSession $session): array
    {
        $session->loadMissing('space', 'resourceConsumptions.grant.resourceType');

        $closed = $session->checked_out_at !== null;
        $end = $session->checked_out_at ?? now();
        $duration = max(0, $end->getTimestamp() - $session->checked_in_at->getTimestamp());

        return [
            'session' => [
                'type' => $session->coworkingType(),
                'id' => $session->id,
                'space' => ['id' => $session->space->id, 'name' => $session->space->translate('name')],
                'started_via' => $session->startSource()->value,
                'checked_in_at' => $session->checked_in_at->toIso8601String(),
                'checked_out_at' => $session->checked_out_at?->toIso8601String(),
                'duration_seconds' => $duration,
                'termination_source' => $session->termination_source?->value,
            ],
            'lines' => [...$this->timeLines($session, $duration, $closed), ...$this->consumptionLines($session)],
            'plan' => $this->planSection($session),
            'totals' => $closed ? [
                'amount_owed' => (string) $session->amount_owed,
                'currency' => $session->currency,
                'payment_state' => $session->payment_state?->value,
                'payment_method' => $session->payment_method?->value,
                'paid_at' => $session->paid_at?->toIso8601String(),
                'payment' => app(PaymentStatePresenter::class)->for($session, $session->user),
                // What was taken off it — offer, coupon, plan discount — as
                // closure locked it (AppliedDiscountPresenter).
                'discounts' => app(AppliedDiscountPresenter::class)->for($session),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeLines(Booking|WalkinSession $session, int $duration, bool $closed): array
    {
        if (! $closed) {
            return [$this->timeLine('api.coworking.report.time_so_far', $duration, null)];
        }

        // A booking's price was fixed at creation (plan discount included);
        // it is shown exactly as stored.
        if ($session instanceof Booking) {
            return [$this->timeLine('api.coworking.report.time_charged', $duration, 'charge', null, (string) $session->amount_owed)];
        }

        $covered = $session->plan_covered_seconds;
        $billable = $duration - $covered;
        $lines = [];

        if ($covered > 0) {
            $lines[] = $this->timeLine('api.coworking.report.time_from_plan', $covered, 'plan');
        }

        if ($billable > 0) {
            // The bill itself, as closing stored it — billing blocks and all —
            // rather than a second pricing that could disagree with it.
            $points = $session->price_points ?? $this->amounts->forRange($session->space, $session->checked_in_at, $session->checked_out_at->copy()->subSeconds($covered));
            $lines[] = $this->timeLine('api.coworking.report.time_charged', $billable, 'charge', $points, (string) $session->amount_owed);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function timeLine(string $labelKey, int $seconds, ?string $source, ?int $points = null, ?string $amount = null): array
    {
        return [
            'kind' => 'time',
            'label' => __($labelKey),
            'quantity' => round($seconds / 3600, 2),
            'unit' => __('api.coworking.report.unit_hour'),
            'source' => $source,
            'points' => $points,
            'amount' => $amount,
            'recorded_at' => null,
        ];
    }

    /**
     * Everything linked to the session except the plan-hours row, which the
     * time lines already represent.
     *
     * @return list<array<string, mixed>>
     */
    private function consumptionLines(Booking|WalkinSession $session): array
    {
        return $session->resourceConsumptions
            ->reject(fn (ResourceConsumption $c) => $c->grant?->resourceType?->slug === CoworkingPlanClassifier::LEGACY_HOURS_SLUG)
            ->map(fn (ResourceConsumption $c) => [
                'kind' => 'resource_consumption',
                'label' => $c->grant?->resourceType?->translate('name'),
                'quantity' => (float) $c->quantity,
                'unit' => $c->grant?->resourceType?->translate('unit_label'),
                'source' => 'plan',
                'points' => null,
                'amount' => null,
                'recorded_at' => $c->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planSection(Booking|WalkinSession $session): ?array
    {
        if (! $session instanceof WalkinSession || $session->membership === null) {
            return null;
        }

        $allowance = $this->allowances->allowanceFor($session->membership, $session->space->space_type, $session->space_id);

        if ($allowance === null) {
            return null;
        }

        $remaining = $this->allowances->remainingSeconds($session->membership, $session->user, $allowance, $session->checked_in_at);

        return [
            'name' => $session->membership->plan->translate('name'),
            'hours_used' => round($session->plan_covered_seconds / 3600, 2),
            // Null for an unlimited plan.
            'hours_remaining' => $remaining === null ? null : round($remaining / 3600, 2),
        ];
    }
}

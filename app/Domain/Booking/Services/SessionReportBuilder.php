<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenOrderLine;
use App\Domain\Kitchen\Services\KitchenOrderSettlement;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
use App\Support\Presenters\AppliedDiscountPresenter;
use App\Support\Presenters\PaymentStatePresenter;
use Illuminate\Database\Eloquent\Collection;

/**
 * The end-of-session report (docs/decisions/coworking-self-service-sessions.md):
 * time, then every transaction linked to the session. `lines` is a generic
 * list on purpose — kitchen orders are lines of kind "order", one per
 * accepted order, with no redesign. Reads only; every figure comes from what
 * closure stored.
 */
class SessionReportBuilder
{
    public function __construct(
        private readonly AmountCalculator $amounts,
        private readonly PlanAllowanceService $allowances,
        private readonly KitchenOrderSettlement $orders,
        private readonly PointConversionService $points,
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
        $orders = $this->orders->owedOn($session);

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
            'lines' => [...$this->timeLines($session, $duration, $closed), ...$this->consumptionLines($session), ...$this->orderLines($orders)],
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
                ...$this->orderTotals($session, $orders),
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
     * One line per accepted kitchen order on it, oldest first: its own
     * charge beside the session's (kitchen orders spec, decision 11), and
     * whether it is paid yet. `quantity` is how many items it holds (every
     * line's quantity), and `items` what they are, at the prices of the
     * moment it was sent.
     *
     * @param  Collection<int, KitchenOrder>  $orders
     * @return list<array<string, mixed>>
     */
    private function orderLines(Collection $orders): array
    {
        $orders->loadMissing('lines.item');

        return $orders
            ->map(fn (KitchenOrder $order) => [
                'kind' => 'order',
                'id' => $order->id,
                'label' => __('api.kitchen_order.description', ['id' => $order->id]),
                'quantity' => (int) $order->lines->sum('quantity'),
                'unit' => null,
                'source' => null,
                'points' => (int) $order->total_points,
                'amount' => $this->points->pointsToUsd((int) $order->total_points),
                'paid' => $order->paid_at !== null,
                'recorded_at' => $order->created_at?->toIso8601String(),
                'status' => $order->status->value,
                'items' => $order->lines->map(fn (KitchenOrderLine $line) => [
                    'sale_item_id' => $line->sale_item_id,
                    'name' => $line->item?->translate('name'),
                    'quantity' => (int) $line->quantity,
                    'unit_price_points' => (int) $line->unit_price_points,
                    'line_points' => (int) $line->line_points,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The orders beside the session's own `amount_owed`, in the same
     * currency: what they come to (`orders_points`, `orders_amount`), what
     * of that is still unpaid — what the desk collects with the session —
     * and the two charges together (`grand_total_amount`). The session's own
     * figures are not changed by them (kitchen orders spec, decision 11).
     *
     * @param  Collection<int, KitchenOrder>  $orders
     * @return array{orders_points: int, orders_unpaid_points: int, orders_unpaid_amount: string, orders_amount: string, grand_total_amount: string}
     */
    private function orderTotals(Booking|WalkinSession $session, Collection $orders): array
    {
        $points = (int) $orders->sum('total_points');
        $amount = $this->points->pointsToUsd($points);
        $unpaidPoints = (int) $orders->whereNull('paid_at')->sum('total_points');

        return [
            'orders_points' => $points,
            'orders_unpaid_points' => $unpaidPoints,
            'orders_unpaid_amount' => $this->points->pointsToUsd($unpaidPoints),
            'orders_amount' => $amount,
            'grand_total_amount' => bcadd((string) ($session->amount_owed ?? '0'), $amount, 2),
        ];
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

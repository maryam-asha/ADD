<?php

namespace App\Domain\Membership\Services;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanResourceGrant;
use App\Domain\Membership\Models\PlanSpaceHour;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\ValueObjects\HourAllowance;
use App\Support\Time\LocalTime;

/**
 * A member's plan as one list of cards, in the order the app shows them:
 * the period, the shared-workspace hours, free hours on a space, discounts,
 * balances, resources. Every card has the same keys; `display` is `ring`
 * when there is a total to draw against and `value` when there is none
 * (unlimited). Hours and discount uses are the person's own, this cycle —
 * on a company plan too.
 */
class CurrentMembershipStats
{
    public function __construct(
        private readonly PlanAllowanceService $allowances,
        private readonly PlanDiscountService $discounts,
        private readonly ResourceQuotaService $resourceQuota,
        private readonly PointConversionService $points,
        private readonly LocalTime $time,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Membership $membership, User $user): array
    {
        $plan = $membership->plan;

        if ($plan === null) {
            return [$this->period($membership)];
        }

        $plan->loadMissing(['spaceHours.space', 'discounts.space', 'balances', 'resourceGrants.resourceType']);

        return array_values(array_filter([
            $this->period($membership),
            $plan->includesCoworking() ? $this->workspaceHours($membership, $user) : null,
            ...$plan->spaceHours->map(fn (PlanSpaceHour $row) => $this->spaceHours($membership, $user, $row))->all(),
            ...$plan->discounts->where('is_active', true)->map(fn (PlanDiscount $d) => $this->discount($membership, $user, $d))->all(),
            ...$this->balances($plan, $membership, $user),
            ...$plan->resourceGrants->map(fn (PlanResourceGrant $g) => $this->resource($membership, $g))->all(),
        ]));
    }

    /** @return array<string, mixed> */
    private function period(Membership $membership): array
    {
        $start = $this->time->toLocal($membership->current_period_start)->startOfDay();
        $today = $this->time->toLocal(now())->startOfDay();

        $total = max(1, (int) ceil($membership->current_period_start->diffInSeconds($membership->current_period_end) / 86400));
        $used = $today->lessThan($start)
            ? 0
            : min($total, (int) $start->diffInDays($today));

        return $this->card('period', 'period', __('api.package.stats.period'), null, 'calendar', 'days', $total, $used,
            fn (int|float|null $remaining) => __('api.package.stats.days_left', ['count' => $remaining]));
    }

    /** @return array<string, mixed> */
    private function workspaceHours(Membership $membership, User $user): array
    {
        $allowance = $this->allowances->allowanceFor($membership, SpaceType::CoSpace);

        return $this->hours('workspace_hours', __('api.package.stats.workspace_hours'), $membership, $user, $allowance);
    }

    /** @return array<string, mixed> */
    private function spaceHours(Membership $membership, User $user, PlanSpaceHour $row): array
    {
        $title = $row->translate('description')
            ?? __('api.package.stats.free_hours_on', ['target' => $this->target($row->space, $row->space_type)]);

        return $this->hours("space_hours:{$row->id}", $title, $membership, $user, new HourAllowance($row, (float) $row->hours, false));
    }

    /** @return array<string, mixed> */
    private function hours(string $key, string $title, Membership $membership, User $user, HourAllowance $allowance): array
    {
        $used = round($this->allowances->spentSeconds($membership, $user, $allowance, now()) / 3600, 2);

        return $this->card($key, 'hours', $title, null, 'clock', 'hours', $allowance->hours, $used,
            fn (int|float|null $remaining) => $remaining === null
                ? __('api.package.stats.hours_used', ['count' => $used])
                : __('api.package.stats.hours_left', ['count' => $remaining]));
    }

    /** @return array<string, mixed> */
    private function discount(Membership $membership, User $user, PlanDiscount $discount): array
    {
        $used = $this->discounts->usesFor($discount, $membership, $user, now());
        $title = $discount->translate('description')
            ?? __('api.package.stats.discount_on', [
                'percent' => (int) $discount->percentage,
                'target' => $this->target($discount->space, $discount->space_type),
            ]);

        return $this->card("discount:{$discount->id}", 'discount', $title, null, 'percent', 'uses', $discount->usage_limit, $used,
            fn (int|float|null $remaining) => $remaining === null
                ? __('api.package.stats.uses_count', ['count' => $used])
                : __('api.package.stats.uses_left', ['count' => $remaining]),
        ) + ['percent_off' => (int) $discount->percentage];
    }

    /**
     * What the plan's wallet grants gave this period and what was spent of
     * them — the same arithmetic `balances` used before these cards.
     * Meeting-room balances count in hours (1 point = 1 hour, as granted).
     *
     * @return list<array<string, mixed>>
     */
    private function balances(Plan $plan, Membership $membership, User $user): array
    {
        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->first();

        if ($wallet === null) {
            return [];
        }

        return $plan->balances->pluck('category')->unique()->map(function (WalletTransactionCategory $category) use ($plan, $membership, $wallet) {
            $row = $plan->balances->firstWhere('category', $category);
            $isHours = $category === WalletTransactionCategory::SpaceSpecific;
            $transactions = fn () => WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('category', $category)
                ->where('created_at', '>=', $membership->current_period_start);

            $granted = (int) $transactions()->where('source', WalletTransactionSource::SubscriptionGrant)->sum('amount');
            $spent = (int) abs($transactions()->where('amount', '<', 0)->sum('amount'));
            $amount = fn (int $points) => $isHours ? $points : (float) $this->points->pointsToUsd($points);
            $format = fn (int|float|null $value) => $isHours
                ? "{$value} ".__('api.package.units.hours')
                : sprintf('$%.2f', (float) $value);

            $key = match ($category) {
                WalletTransactionCategory::Cafe => 'cafe',
                WalletTransactionCategory::PrintingInternet => 'printing',
                WalletTransactionCategory::SpaceSpecific => 'meeting',
                default => 'general',
            };

            return $this->card(
                "balance:{$category->value}",
                'balance',
                $row?->translate('description') ?? __('api.package.balances.'.$key),
                null,
                match ($category) {
                    WalletTransactionCategory::Cafe => 'coffee',
                    WalletTransactionCategory::PrintingInternet => 'printer',
                    WalletTransactionCategory::SpaceSpecific => 'users',
                    default => 'wallet',
                },
                $isHours ? 'hours' : 'usd',
                $amount($granted),
                $amount($spent),
                fn (int|float|null $remaining) => __('api.package.remaining_label', ['amount' => $format($remaining)]),
            );
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function resource(Membership $membership, PlanResourceGrant $grant): array
    {
        $type = $grant->resourceType;
        $total = (float) $grant->quantity;
        $remaining = $type === null ? null : $this->resourceQuota->remainingFor($membership, $type);
        $used = $remaining === null ? 0.0 : round($total - $remaining, 2);

        return $this->card(
            "resource:{$grant->id}",
            'resource',
            $grant->translate('description') ?? $type?->translate('name') ?? '',
            null,
            $type?->icon ?? 'wallet',
            $type?->translate('unit_label') ?? '',
            $total,
            $used,
            fn (int|float|null $left) => __('api.package.stats.left_of', ['remaining' => $left, 'total' => $total]),
        );
    }

    /**
     * @param  callable(int|float|null): string  $label  given what is left (null when unlimited)
     * @return array<string, mixed>
     */
    private function card(string $key, string $type, string $title, ?string $subtitle, string $icon, string $unit, int|float|null $total, int|float $used, callable $label): array
    {
        $remaining = $total === null ? null : max(0, $total - $used);

        return [
            'key' => $key,
            'type' => $type,
            'display' => $total === null ? 'value' : 'ring',
            'title' => $title,
            'subtitle' => $subtitle,
            'icon' => $icon,
            'unit' => $unit,
            'total' => $total,
            'used' => $used,
            'remaining' => $remaining,
            'percent' => $total === null ? null : ($total > 0 ? (int) min(100, max(0, round($used / $total * 100))) : 0),
            'label' => $label($remaining),
        ];
    }

    private function target(?Space $space, ?SpaceType $type): string
    {
        return $space?->translate('name')
            ?? ($type !== null ? __('api.space.types.'.$type->value) : __('api.package.stats.everywhere'));
    }
}

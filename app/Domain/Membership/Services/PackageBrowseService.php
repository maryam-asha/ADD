<?php

namespace App\Domain\Membership\Services;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanFeature;
use App\Domain\Membership\Models\PlanResourceGrant;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Support\Presenters\PriceLabelPresenter;

class PackageBrowseService
{
    public function __construct(
        private readonly PointConversionService $points,
        private readonly MembershipResolver $membershipResolver,
        private readonly ResourceQuotaService $resourceQuota,
        private readonly PriceLabelPresenter $prices,
    ) {}

    /**
     * The full package catalog (for the "Packages" tab) — every active
     * subscription plan, each carrying `is_current` so the client can mark
     * whichever one the member is already subscribed to. Deliberately does
     * not include the member's own usage — that's `getCurrentSubscription()`,
     * a separate call for the separate "My Subscription" tab.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAvailablePackages(User $user, ?string $moduleFilter = null): array
    {
        $activeMembership = $this->membershipResolver->activeFor($user);

        return $this->formatAvailablePackages($activeMembership, $moduleFilter);
    }

    /**
     * The member's own active plan (for the "My Subscription" tab): status,
     * billing cycle, benefit badges, and this-cycle usage per balance
     * category. `null` when the member has no active membership — a normal
     * state, not an error.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentSubscription(User $user): ?array
    {
        $activeMembership = $this->membershipResolver->activeFor($user);

        return $activeMembership !== null
            ? $this->formatCurrentPlan($activeMembership, $user)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCurrentPlan(Membership $membership, User $user): array
    {
        $plan = $membership->plan;
        $planName = $plan ? ($plan->translate('name') ?? 'Plan') : 'Plan';

        $expiryFormatted = $membership->current_period_end->format('M d, Y');
        $cycleStart = $membership->current_period_start->format('M d');
        $cycleEnd = $membership->current_period_end->format('M d, Y');

        $capabilities = $plan ? $this->resolvePlanCapabilities($plan) : [];

        return [
            'id' => $plan?->id,
            'membership_id' => $membership->id,
            'name' => $planName,
            'module' => $plan?->planModule ? [
                'id' => $plan->planModule->id,
                'name' => $plan->planModule->translate('name'),
                'slug' => $plan->planModule->slug,
                'icon' => $plan->planModule->icon,
            ] : null,
            'tag' => __('api.package.current_plan_tag'),
            'status' => 'active',
            'status_label' => __('api.space.statuses.active'),
            'is_active' => true,
            'current_period_start' => $membership->current_period_start->toIso8601String(),
            'current_period_end' => $membership->current_period_end->toIso8601String(),
            // A Dedicated Desk subscription's desks by the code on them — an
            // empty list until staff tie one; null for any other plan
            // (docs/decisions/coworking-seat-pool.md).
            'desks' => $plan?->isDedicatedDesk()
                ? $membership->seatDesks()->orderBy('seats_desks.id')->get()
                    ->map(fn ($desk) => ['code' => $desk->code, 'label' => $desk->label])->values()->all()
                : null,
            'validity_text' => __('api.package.active_until', ['date' => $expiryFormatted]),
            'billing_cycle_text' => __('api.package.billing_cycle', ['start' => $cycleStart, 'end' => $cycleEnd]),
            'benefits_title' => __('api.package.benefits_title'),
            'benefits' => $this->defaultBenefits($capabilities),
            'discounts' => $plan ? $this->formatDiscounts($plan) : [],
            'resources' => $plan ? $this->formatCurrentPlanResources($plan, $membership) : [],
            'usage_title' => __('api.package.usage_title'),
            'balance_rollover_notice' => __('api.package.balance_rollover_notice'),
            'balances' => $plan ? $this->formatCurrentPlanUsage($plan, $membership, $user) : [],
        ];
    }

    /**
     * Which of the four quick-glance badges (Café / Printing / Meeting /
     * Discounts) this plan actually grants — one clear mechanism per badge,
     * so a plan with no meeting-room balance never claims "Meeting: Included".
     * Deliberately independent of `plan_features` free text: that list can
     * say anything, while this reflects what's actually configured and
     * enforced (`plan_balances` categories, active `plan_discounts`).
     *
     * @return array<string, bool>
     */
    private function resolvePlanCapabilities(Plan $plan): array
    {
        return [
            'cafe' => $plan->balances->contains(fn (PlanBalance $b) => $b->category === WalletTransactionCategory::Cafe),
            'printing' => $plan->balances->contains(fn (PlanBalance $b) => $b->category === WalletTransactionCategory::PrintingInternet),
            'meeting' => $plan->balances->contains(fn (PlanBalance $b) => $b->category === WalletTransactionCategory::SpaceSpecific),
            'discounts' => $plan->discounts->where('is_active', true)->isNotEmpty(),
        ];
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function defaultBenefits(array $capabilities): array
    {
        $all = [
            'cafe' => ['key' => 'cafe_access', 'title' => __('api.package.benefits.cafe_access'), 'subtitle' => __('api.package.benefits.included'), 'icon' => 'coffee'],
            'printing' => ['key' => 'printing_credits', 'title' => __('api.package.benefits.printing_credits'), 'subtitle' => __('api.package.benefits.included'), 'icon' => 'printer'],
            'meeting' => ['key' => 'meeting_rooms', 'title' => __('api.package.benefits.meeting_rooms'), 'subtitle' => __('api.package.benefits.included'), 'icon' => 'users'],
            'discounts' => ['key' => 'member_discounts', 'title' => __('api.package.benefits.member_discounts'), 'subtitle' => __('api.package.benefits.included'), 'icon' => 'percent'],
        ];

        return collect($all)->only(array_keys(array_filter($capabilities)))->values()->all();
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function defaultTags(array $capabilities): array
    {
        $all = [
            'cafe' => ['key' => 'cafe', 'label' => __('api.package.tags.cafe'), 'icon' => 'coffee'],
            'printing' => ['key' => 'printing', 'label' => __('api.package.tags.printing'), 'icon' => 'printer'],
            'meeting' => ['key' => 'meeting', 'label' => __('api.package.tags.meeting'), 'icon' => 'users'],
            'discounts' => ['key' => 'discounts', 'label' => __('api.package.tags.discounts'), 'icon' => 'percent'],
        ];

        return collect($all)->only(array_keys(array_filter($capabilities)))->values()->all();
    }

    /**
     * Per-category usage for "Your Usage This Cycle": `granted` is what the
     * plan credited this membership period (`source = SubscriptionGrant`,
     * `created_at >= current_period_start`), `used` is everything spent from
     * that category in the same window, `remaining = granted - used`. This
     * is a cycle-scoped display figure, not `WalletService`'s general
     * spendable-now balance (which also folds in top-ups/gifts and expiry
     * eligibility) — the two can differ if a member also received a manual
     * top-up in the same category.
     *
     * `space_specific` (Meeting Rooms) is displayed in **hours**, not
     * dollars: `hourly_rate_points` is per-space and not fixed, so there is
     * no universal points→hours rate, but the product already grants and
     * describes this category in hour counts 1:1 (e.g. PlanSeeder's "Meeting
     * Room Hours", amount_points=80) — this keeps that same convention
     * rather than inventing a real conversion.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatCurrentPlanUsage(Plan $plan, Membership $membership, User $user): array
    {
        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->first();
        if (! $wallet) {
            return [];
        }

        $categories = $plan->balances->pluck('category')->unique();

        return $categories->map(function (WalletTransactionCategory $category) use ($plan, $membership, $wallet) {
            $balanceRow = $plan->balances->firstWhere('category', $category);
            $isHours = $category === WalletTransactionCategory::SpaceSpecific;

            $grantedPoints = (int) WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('category', $category)
                ->where('source', WalletTransactionSource::SubscriptionGrant)
                ->where('created_at', '>=', $membership->current_period_start)
                ->sum('amount');

            $usedPoints = (int) abs(WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('category', $category)
                ->where('amount', '<', 0)
                ->where('created_at', '>=', $membership->current_period_start)
                ->sum('amount'));

            $remainingPoints = max(0, $grantedPoints - $usedPoints);
            $percentageUsed = $grantedPoints > 0 ? (int) round(($usedPoints / $grantedPoints) * 100) : 0;

            $format = fn (int $points) => $isHours
                ? (string) $points
                : sprintf('$%.2f', (float) $this->points->pointsToUsd($points));

            $categoryKey = match ($category) {
                WalletTransactionCategory::Cafe => 'cafe',
                WalletTransactionCategory::PrintingInternet => 'printing',
                WalletTransactionCategory::SpaceSpecific => 'meeting',
                default => 'general',
            };

            $unitLabel = $isHours ? __('api.package.units.hours') : null;
            $amountLabel = fn (string $formatted) => $isHours ? "{$formatted} {$unitLabel}" : $formatted;

            return [
                'category' => $category->value,
                'title' => $balanceRow?->translate('description') ?? __('api.package.balances.'.$categoryKey),
                'unit' => $isHours ? 'hours' : 'usd',
                'granted' => $isHours ? $grantedPoints : (float) $this->points->pointsToUsd($grantedPoints),
                'used' => $isHours ? $usedPoints : (float) $this->points->pointsToUsd($usedPoints),
                'remaining' => $isHours ? $remainingPoints : (float) $this->points->pointsToUsd($remainingPoints),
                'percentage_used' => $percentageUsed,
                'formatted_granted' => $amountLabel($format($grantedPoints)),
                'formatted_used' => $amountLabel($format($usedPoints)),
                'formatted_remaining' => $amountLabel($format($remainingPoints)),
                'remaining_label' => __('api.package.remaining_label', ['amount' => $amountLabel($format($remainingPoints))]),
                // Backward-compatible aliases: `amount`/`formatted` used to be
                // the only fields this array exposed (the live remaining
                // balance) — kept so an existing consumer reading just those
                // two keys still gets a sensible current value.
                'amount' => $isHours ? $remainingPoints : (float) $this->points->pointsToUsd($remainingPoints),
                'formatted' => $format($remainingPoints),
                'icon' => match ($category) {
                    WalletTransactionCategory::Cafe => 'coffee',
                    WalletTransactionCategory::PrintingInternet => 'printer',
                    WalletTransactionCategory::SpaceSpecific => 'users',
                    default => 'wallet',
                },
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatAvailablePackages(?Membership $activeMembership, ?string $moduleFilter = null): array
    {
        $query = Plan::query()
            ->with(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType'])
            ->publiclyListable()
            ->where('is_subscription', true);

        if ($moduleFilter !== null && $moduleFilter !== '') {
            $query->where(function ($q) use ($moduleFilter) {
                if (is_numeric($moduleFilter)) {
                    $q->where('plan_module_id', (int) $moduleFilter);
                } else {
                    $q->whereHas('planModule', fn ($mq) => $mq->where('slug', $moduleFilter));
                }
            });
        }

        $plans = $query->orderBy('order')
            ->orderBy('price_points')
            ->get();

        // The catalog is a lightweight browsing list, not a details screen:
        // `features` (a free-text bullet list, up to 9 items on the seeded
        // plans) is capped at 3 with a `more_features_count` remainder, and
        // `tags`/`balances_grant`/`discounts`/`resources_grant` are dropped
        // entirely — the card UI shows none of them, only "View details".
        // `getPackageDetails()` is the one place a client reads the full set.
        return $plans->map(function (Plan $plan) use ($activeMembership) {
            $isCurrent = $activeMembership !== null && $activeMembership->plan_id === $plan->id;
            $details = $this->formatPackage($plan, $isCurrent);

            $allFeatures = $details['features'];
            $details['features'] = array_slice($allFeatures, 0, 3);
            $details['more_features_count'] = max(0, count($allFeatures) - 3);

            unset($details['tags'], $details['balances_grant'], $details['discounts'], $details['resources_grant']);

            return $details;
        })->values()->all();
    }

    /**
     * One plan's full details (untruncated `features`), for the "View
     * details" screen. `null` when the plan doesn't exist or isn't a
     * currently-browsable subscription plan — same visibility rule as the
     * catalog list, so a client can't probe inactive/legacy plan ids.
     *
     * A private plan (`is_public = false`) is hidden the same way, with one
     * deliberate exception: the member who actually holds it must be able to
     * open the details of their own subscription. Operations assigns these
     * plans from the dashboard, so the holder never browsed to it in the
     * first place — without this, their own package screen would 404.
     *
     * @return array<string, mixed>|null
     */
    public function getPackageDetails(Plan $plan, ?User $user): ?array
    {
        if (! $plan->is_active || ! $plan->is_subscription) {
            return null;
        }

        $plan->loadMissing(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType']);

        $activeMembership = $user !== null ? $this->membershipResolver->activeFor($user) : null;
        $isCurrent = $activeMembership !== null && $activeMembership->plan_id === $plan->id;

        if (! $plan->is_public && ! $isCurrent) {
            return null;
        }

        return $this->formatPackage($plan, $isCurrent);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPackage(Plan $plan, bool $isCurrent): array
    {
        $pricePoints = (int) $plan->price_points;
        $price = (float) $this->points->pointsToUsd($pricePoints);
        $formattedPrice = sprintf('$%.2f /%s', $price, __('api.package.per_month'));
        $formattedPointPrice = sprintf('%s %s /%s', number_format($pricePoints), __('api.package.points_unit'), __('api.package.per_month'));

        $name = $plan->translate('name') ?? 'Plan';
        $subtitle = $plan->translate('subtitle');
        if (! $subtitle) {
            $lower = strtolower($name);
            $subtitleKey = match (true) {
                str_contains($lower, 'start') => 'starter',
                str_contains($lower, 'prof') => 'professional',
                str_contains($lower, 'enter') => 'enterprise',
                default => 'starter',
            };
            $subtitle = __('api.package.subtitles.'.$subtitleKey);
        }

        $features = $plan->features->isNotEmpty()
            ? $plan->features->map(fn (PlanFeature $f) => [
                'id' => $f->id,
                'name' => $f->translate('name'),
                'value' => $f->translate('value'),
                'icon' => $f->icon,
                'is_included' => $f->is_included,
            ])->values()->all()
            : [];

        $balancesGrant = $plan->balances->isNotEmpty()
            ? $plan->balances->map(fn (PlanBalance $b) => [
                'category' => $b->category->value,
                'title' => $b->translate('description') ?? __('api.package.balances.'.($b->category === WalletTransactionCategory::Cafe ? 'cafe' : 'printing')),
                'amount_points' => (int) $b->amount_points,
                'amount' => (float) $this->points->pointsToUsd((int) $b->amount_points),
                'formatted' => sprintf('$%.2f', (float) $this->points->pointsToUsd((int) $b->amount_points)),
                'icon' => match ($b->category) {
                    WalletTransactionCategory::Cafe => 'coffee',
                    WalletTransactionCategory::PrintingInternet => 'printer',
                    WalletTransactionCategory::SpaceSpecific => 'clock',
                    default => 'wallet',
                },
            ])->values()->all()
            : [];

        $tags = $this->defaultTags($this->resolvePlanCapabilities($plan));

        return [
            'id' => $plan->id,
            'name' => $name,
            'module' => $plan->planModule ? [
                'id' => $plan->planModule->id,
                'name' => $plan->planModule->translate('name'),
                'slug' => $plan->planModule->slug,
                'icon' => $plan->planModule->icon,
            ] : null,
            'subtitle' => $subtitle,
            'formatted_price' => $formattedPrice,
            'formatted_point_price' => $formattedPointPrice,
            // A sale live now: what a purchase opened now would cost.
            'sale' => $this->prices->sale(null, $pricePoints, null, false, 'api.package'),
            'duration_days' => $plan->duration_days,
            'tags' => $tags,
            'features' => $features,
            'balances_grant' => $balancesGrant,
            'discounts' => $this->formatDiscounts($plan),
            'resources_grant' => $plan->resourceGrants->isNotEmpty()
                ? $plan->resourceGrants->map(fn (PlanResourceGrant $g) => [
                    'id' => $g->id,
                    'resource_type_id' => $g->resource_type_id,
                    'slug' => $g->resourceType?->slug,
                    'name' => $g->resourceType?->translate('name'),
                    'unit_label' => $g->resourceType?->translate('unit_label'),
                    'icon' => $g->resourceType?->icon,
                    'granted' => (float) $g->quantity,
                ])->values()->all()
                : [],
            'is_current' => $isCurrent,
        ];
    }

    /**
     * Raw `space_id`/`space_type` rather than a pre-translated "applies to
     * Room #3" sentence — the client already resolves `balances_grant`'s raw
     * `category` into a label itself, and has its own SpaceType label table.
     * `title` prefers the admin's own description, same fallback pattern as
     * `balancesGrant` below. `is_active` only — no per-membership usage-
     * exhaustion filtering, matching how `balances`/`balances_grant` already
     * reflect general plan configuration, not live per-member consumption.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatDiscounts(Plan $plan): array
    {
        return $plan->discounts->where('is_active', true)->map(fn (PlanDiscount $d) => [
            'id' => $d->id,
            'percentage' => (int) $d->percentage,
            'title' => $d->translate('description') ?? __('api.package.discounts.default', ['percentage' => $d->percentage]),
            'space_id' => $d->space_id,
            'space_type' => $d->space_type?->value,
            'usage_limit' => $d->usage_limit,
            'icon' => 'percent',
        ])->values()->all();
    }

    /**
     * `remaining` is a live, per-membership read (ResourceQuotaService),
     * unlike `discounts`/`balances_grant` above which just reflect general
     * plan configuration — the current plan's grants are the one place a
     * member can see what they've actually used so far.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatCurrentPlanResources(Plan $plan, Membership $membership): array
    {
        return $plan->resourceGrants->map(fn (PlanResourceGrant $g) => [
            'id' => $g->id,
            'resource_type_id' => $g->resource_type_id,
            'slug' => $g->resourceType?->slug,
            'name' => $g->resourceType?->translate('name'),
            'unit_label' => $g->resourceType?->translate('unit_label'),
            'icon' => $g->resourceType?->icon,
            'granted' => (float) $g->quantity,
            'remaining' => $g->resourceType ? $this->resourceQuota->remainingFor($membership, $g->resourceType) : null,
            'title' => $g->translate('description'),
        ])->values()->all();
    }
}

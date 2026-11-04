<?php

namespace App\Domain\Membership\Services;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Models\PlanFeature;
use App\Domain\Pricing\Services\OfferResolver;
use App\Domain\Pricing\Services\PlanSavingCalculator;
use App\Support\Presenters\PriceLabelPresenter;

class PackageBrowseService
{
    public function __construct(
        private readonly PointConversionService $points,
        private readonly MembershipResolver $membershipResolver,
        private readonly PlanAllowanceService $allowances,
        private readonly PriceLabelPresenter $prices,
        private readonly OfferResolver $offers,
        private readonly PlanSavingCalculator $savings,
        private readonly CurrentMembershipStats $stats,
        private readonly PlanPurchaseEligibility $eligibility,
    ) {}

    /** @var array<int, ?string> why each member may not subscribe now, by user id */
    private array $blockedReasons = [];

    /**
     * The full package catalog (for the "Packages" tab) — every active
     * subscription plan, each carrying `is_current` so the client can mark
     * whichever one the member is already subscribed to. Deliberately does
     * not include the member's own usage — that's `getCurrentSubscription()`,
     * a separate call for the separate "My Subscription" tab.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAvailablePackages(?User $user, ?string $moduleFilter = null): array
    {
        // A visitor (no account) holds no plan, so none is marked current.
        $activeMembership = $user !== null ? $this->membershipResolver->activeFor($user) : null;

        return $this->formatAvailablePackages($activeMembership, $user, $moduleFilter);
    }

    /**
     * The same catalog for a screen with no member — the reception kiosk.
     * Prices fall back to the base currency, only offers for everyone show,
     * and `is_current` is always false.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPublicPackages(?string $moduleFilter = null): array
    {
        return $this->formatAvailablePackages(null, null, $moduleFilter);
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
     * memberships/current: whose plan it is, its period and this cycle, and
     * one list of cards to draw (CurrentMembershipStats).
     *
     * @return array<string, mixed>
     */
    private function formatCurrentPlan(Membership $membership, User $user): array
    {
        $plan = $membership->plan;
        [$cycleStart, $cycleEnd] = $this->allowances->cycleFor($membership, now());
        $isCompany = $membership->owner_type === OwnerType::Company;

        return [
            'membership_id' => $membership->id,
            'via' => $isCompany ? 'company' : 'personal',
            'company' => $isCompany
                ? ['id' => $membership->owner_id, 'name' => Company::query()->whereKey($membership->owner_id)->value('legal_name')]
                : null,
            'plan' => [
                'id' => $plan?->id,
                'name' => $plan?->translate('name'),
                'module' => $plan?->planModule ? [
                    'id' => $plan->planModule->id,
                    'name' => $plan->planModule->translate('name'),
                    'slug' => $plan->planModule->slug,
                    'icon' => $plan->planModule->icon,
                ] : null,
                'seat' => ($plan?->coworking_seat ?? CoworkingSeat::None)->value,
                // A Dedicated Desk subscription's desks by the code on them — an
                // empty list until staff tie one; null for any other plan
                // (docs/decisions/coworking-seat-pool.md).
                'desks' => $plan?->isDedicatedDesk()
                    ? $membership->seatDesks()->orderBy('seats_desks.id')->get()
                        ->map(fn ($desk) => ['code' => $desk->code, 'label' => $desk->label])->values()->all()
                    : null,
            ],
            'period' => [
                'start' => $membership->current_period_start->toIso8601String(),
                'end' => $membership->current_period_end->toIso8601String(),
                'cycle_start' => $cycleStart->toIso8601String(),
                'cycle_end' => $cycleEnd->toIso8601String(),
            ],
            'stats' => $this->stats->for($membership, $user),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatAvailablePackages(?Membership $activeMembership, ?User $user, ?string $moduleFilter = null): array
    {
        $query = Plan::query()
            ->with(['planModule', 'balances', 'features', 'discounts.space', 'resourceGrants.resourceType', 'referencePlan', 'referenceSpace', 'spaceHours.space'])
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
        // `balances_grant`/`benefits` are dropped entirely — the card UI
        // shows neither, only "View details".
        // `getPackageDetails()` is the one place a client reads the full set.
        return $plans->map(function (Plan $plan) use ($activeMembership, $user) {
            $isCurrent = $activeMembership !== null && $activeMembership->plan_id === $plan->id;
            $details = $this->formatPackage($plan, $isCurrent, $user);

            $allFeatures = $details['features'];
            $details['features'] = array_slice($allFeatures, 0, 3);
            $details['more_features_count'] = max(0, count($allFeatures) - 3);

            unset($details['balances_grant'], $details['benefits']);

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

        $plan->loadMissing(['planModule', 'balances', 'features', 'discounts.space', 'resourceGrants.resourceType', 'spaceHours.space']);

        $activeMembership = $user !== null ? $this->membershipResolver->activeFor($user) : null;
        $isCurrent = $activeMembership !== null && $activeMembership->plan_id === $plan->id;

        if (! $plan->is_public && ! $isCurrent) {
            return null;
        }

        return $this->formatPackage($plan, $isCurrent, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPackage(Plan $plan, bool $isCurrent, ?User $user): array
    {
        $pricePoints = (int) $plan->price_points;
        $pricing = $this->prices->plan($pricePoints, $plan->duration_days, $user);

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

        $blockedReason = $this->subscribeBlockedReason($user);

        return [
            'id' => $plan->id,
            // One personal plan at a time: the app disables "Subscribe" and
            // says why, instead of waiting for the purchase's 422.
            'can_subscribe' => $blockedReason === null,
            'subscribe_blocked_reason' => $blockedReason,
            'name' => $name,
            'module' => $plan->planModule ? [
                'id' => $plan->planModule->id,
                'name' => $plan->planModule->translate('name'),
                'slug' => $plan->planModule->slug,
                'icon' => $plan->planModule->icon,
            ] : null,
            'seat' => ($plan->coworking_seat ?? CoworkingSeat::None)->value,
            'subtitle' => $subtitle,
            'pricing' => $pricing,
            // The best offer live now for this member: what a purchase
            // opened now would cost them.
            'sale' => $this->prices->sale($this->offers->planNow($plan, $user), $pricePoints, $user, $pricing['unit'], 'api.package'),
            // What it saves against its reference plan or hourly rate — its
            // own price, not a discount; shown beside any offer.
            'saving' => $this->prices->saving($this->savings->for($plan), $user),
            'duration_days' => $plan->duration_days,
            'features' => $features,
            'balances_grant' => $balancesGrant,
            // What else it gives — workspace and free hours, discounts,
            // resources — as the cards memberships/current draws, nothing used.
            'benefits' => $this->stats->forPlan($plan),
            'is_current' => $isCurrent,
        ];
    }

    private function subscribeBlockedReason(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if (! array_key_exists($user->id, $this->blockedReasons)) {
            $blocking = $this->eligibility->blockingFor(OwnerType::User, $user->id);
            $this->blockedReasons[$user->id] = $blocking === null ? null : $this->eligibility->reason($blocking);
        }

        return $this->blockedReasons[$user->id];
    }
}

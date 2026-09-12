<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Models\PlanFeature;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PackageBrowseService
{
    /**
     * @return array<string, mixed>
     */
    public function getPackages(User $user, ?string $moduleFilter = null): array
    {
        $activeMembership = $this->resolveActiveMembership($user);

        $currentPlanData = $activeMembership !== null
            ? $this->formatCurrentPlan($activeMembership, $user)
            : null;

        $availablePackages = $this->formatAvailablePackages($activeMembership, $moduleFilter);

        return [
            'current_plan' => $currentPlanData,
            'available_packages' => $availablePackages,
        ];
    }

    private function resolveActiveMembership(User $user): ?Membership
    {
        // 1. Check user's direct personal active membership
        $membership = Membership::query()
            ->where('owner_type', OwnerType::User)
            ->where('owner_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->where('current_period_end', '>=', now())
            ->with(['plan.planModule', 'plan.balances', 'plan.features'])
            ->latest('id')
            ->first();

        if ($membership !== null) {
            return $membership;
        }

        // 2. Check if member belongs to a company with an active membership
        $companyIds = $user->companies()->pluck('companies.id');
        if ($companyIds->isNotEmpty()) {
            return Membership::query()
                ->where('owner_type', OwnerType::Company)
                ->whereIn('owner_id', $companyIds)
                ->where('status', MembershipStatus::Active)
                ->where('current_period_end', '>=', now())
                ->with(['plan.planModule', 'plan.balances', 'plan.features'])
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCurrentPlan(Membership $membership, User $user): array
    {
        $plan = $membership->plan;
        $planName = $plan ? ($plan->translate('name') ?? 'Plan') : 'Plan';

        $cafeBalance = $this->getCategoryBalance($user, WalletTransactionCategory::Cafe);
        $printingBalance = $this->getCategoryBalance($user, WalletTransactionCategory::PrintingInternet);

        $expiryFormatted = $membership->current_period_end->format('M d, Y');
        $expiryShort = $membership->current_period_end->format('M d');

        $benefits = ($plan && $plan->features->isNotEmpty())
            ? $plan->features->map(fn (PlanFeature $f) => [
                'key' => Str::slug($f->name['en'] ?? 'feature'),
                'title' => $f->translate('name'),
                'subtitle' => $f->translate('value') ?? __('api.package.benefits.included'),
                'icon' => $f->icon ?? 'check',
            ])->values()->all()
            : [
                [
                    'key' => 'cafe_access',
                    'title' => __('api.package.benefits.cafe_access'),
                    'subtitle' => __('api.package.benefits.included'),
                    'icon' => 'coffee',
                ],
                [
                    'key' => 'printing_credits',
                    'title' => __('api.package.benefits.printing_credits'),
                    'subtitle' => __('api.package.benefits.included'),
                    'icon' => 'printer',
                ],
                [
                    'key' => 'meeting_rooms',
                    'title' => __('api.package.benefits.meeting_rooms'),
                    'subtitle' => __('api.package.benefits.included'),
                    'icon' => 'users',
                ],
                [
                    'key' => 'member_discounts',
                    'title' => __('api.package.benefits.member_discounts'),
                    'subtitle' => __('api.package.benefits.included'),
                    'icon' => 'percent',
                ],
            ];

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
            'current_period_end' => $membership->current_period_end->toIso8601String(),
            'validity_text' => __('api.package.active_until', ['date' => $expiryFormatted]),
            'benefits' => $benefits,
            'balances' => [
                [
                    'category' => 'cafe',
                    'title' => __('api.package.balances.cafe'),
                    'amount' => $cafeBalance,
                    'formatted' => sprintf('$%.2f', $cafeBalance),
                    'expires_at' => __('api.package.expires_on', ['date' => $expiryShort]),
                    'icon' => 'coffee',
                ],
                [
                    'category' => 'printing_internet',
                    'title' => __('api.package.balances.printing'),
                    'amount' => $printingBalance,
                    'formatted' => sprintf('$%.2f', $printingBalance),
                    'expires_at' => __('api.package.expires_on', ['date' => $expiryShort]),
                    'icon' => 'printer',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatAvailablePackages(?Membership $activeMembership, ?string $moduleFilter = null): array
    {
        $query = Plan::query()
            ->with(['planModule', 'balances', 'features'])
            ->where('is_active', true)
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
            ->orderBy('price')
            ->get();

        return $plans->map(function (Plan $plan) use ($activeMembership) {
            $isCurrent = $activeMembership !== null && $activeMembership->plan_id === $plan->id;
            $price = (float) $plan->price;
            $currency = $plan->pricing_currency ?? 'USD';
            $symbol = $currency === 'USD' ? '$' : $currency;
            $formattedPrice = sprintf('%s%.2f /%s', $symbol, $price, __('api.package.per_month'));

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
                $subtitle = __('api.package.subtitles.' . $subtitleKey);
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
                    'title' => $b->translate('description') ?? __('api.package.balances.' . ($b->category === WalletTransactionCategory::Cafe ? 'cafe' : 'printing')),
                    'amount' => (float) $b->amount,
                    'formatted' => sprintf('$%.2f', (float) $b->amount),
                    'icon' => match ($b->category) {
                        WalletTransactionCategory::Cafe => 'coffee',
                        WalletTransactionCategory::PrintingInternet => 'printer',
                        WalletTransactionCategory::SpaceSpecific => 'clock',
                        default => 'wallet',
                    },
                ])->values()->all()
                : [];

            $tags = $plan->features->isNotEmpty()
                ? $plan->features->map(fn (PlanFeature $f) => [
                    'key' => Str::slug($f->name['en'] ?? 'feature'),
                    'label' => $f->translate('name'),
                    'icon' => $f->icon ?? 'check',
                ])->values()->all()
                : [
                    ['key' => 'cafe', 'label' => __('api.package.tags.cafe'), 'icon' => 'coffee'],
                    ['key' => 'printing', 'label' => __('api.package.tags.printing'), 'icon' => 'printer'],
                    ['key' => 'meeting', 'label' => __('api.package.tags.meeting'), 'icon' => 'users'],
                    ['key' => 'discounts', 'label' => __('api.package.tags.discounts'), 'icon' => 'percent'],
                ];

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
                'price' => $price,
                'currency' => $currency,
                'currency_symbol' => $symbol,
                'billing_period' => __('api.package.per_month'),
                'formatted_price' => $formattedPrice,
                'duration_days' => $plan->duration_days,
                'included_hours' => (float) $plan->included_hours,
                'tags' => $tags,
                'features' => $features,
                'balances_grant' => $balancesGrant,
                'is_current' => $isCurrent,
            ];
        })->values()->all();
    }

    private function getCategoryBalance(User $user, WalletTransactionCategory $category): float
    {
        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->first();
        if (! $wallet) {
            return 0.0;
        }

        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('category', $category)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('allowedUsers')
            ->get();

        $eligible = $transactions->filter(
            fn (WalletTransaction $tx) => $tx->allowedUsers->isEmpty()
                || $tx->allowedUsers->contains('id', $user->id)
        );

        $total = '0.00';
        foreach ($eligible as $tx) {
            $total = bcadd($total, (string) $tx->amount, 2);
        }

        return max(0.0, (float) $total);
    }
}

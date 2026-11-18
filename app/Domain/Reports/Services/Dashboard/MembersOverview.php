<?php

namespace App\Domain\Reports\Services\Dashboard;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The members section of the dashboard's home page: sign-ups over the
 * period against the one before, the subscriptions live now by plan, and
 * the subscriptions and office leases about to end. A subscription already
 * renewed is not about to end — its renewal picks up where it stops.
 */
final class MembersOverview
{
    public function __construct(private readonly LocalTime $time) {}

    /** @return array<string, mixed> */
    public function for(DashboardPeriod $period, ?Branch $branch): array
    {
        $previous = $period->previous();

        return [
            'period' => $period->toArray(),
            'previous_period' => $previous->toArray(),
            'new_members' => [
                'current' => $this->newMembers($period, $branch),
                'previous' => $this->newMembers($previous, $branch),
            ],
            'memberships' => $this->memberships($branch),
            'office_leases' => $this->leases($branch),
        ];
    }

    private function newMembers(DashboardPeriod $period, ?Branch $branch): int
    {
        return User::role('member')
            ->where('created_at', '>=', $period->start)
            ->where('created_at', '<', $period->end)
            ->when($branch, fn (Builder $q) => $q->whereHas('branchMemberships', fn (Builder $m) => $m->where('branch_id', $branch->id)))
            ->count();
    }

    /** @return array<string, mixed> */
    private function memberships(?Branch $branch): array
    {
        $live = Membership::query()
            ->live()
            ->when($branch, fn (Builder $q) => $q->where('branch_id', $branch->id));

        $byPlan = (clone $live)
            ->selectRaw('plan_id, COUNT(*) AS aggregate')
            ->groupBy('plan_id')
            ->toBase()
            ->pluck('aggregate', 'plan_id')
            ->map(fn ($count) => (int) $count)
            ->sortDesc();
        $plans = Plan::query()->whereKey($byPlan->keys())->get()->keyBy('id');
        $locale = app()->getLocale();

        $ending = fn (int $days) => (clone $live)
            ->where('current_period_end', '<=', now()->addDays($days))
            ->whereDoesntHave('renewal', fn (Builder $renewal) => $renewal->where('status', MembershipStatus::Active))
            ->count();

        return [
            'live' => (int) $byPlan->sum(),
            'by_plan' => $byPlan->map(fn (int $count, $planId) => [
                'plan' => ['id' => (int) $planId, 'name' => $plans->get($planId)?->translate('name', $locale)],
                'count' => $count,
            ])->values()->all(),
            'ending_within_7_days' => $ending(7),
            'ending_within_30_days' => $ending(30),
        ];
    }

    /**
     * Leases whose office is held today, and those whose last day falls in
     * the next 30 — `terminated_on` being the first day without access.
     *
     * @return array<string, int>
     */
    private function leases(?Branch $branch): array
    {
        $today = CarbonImmutable::instance($this->time->toLocal(now()))->startOfDay();
        $lastDay = $today->addDays(30)->toDateString();
        $holding = OfficeLease::query()
            ->holdingOn($today->toDateString())
            ->when($branch, fn (Builder $q) => $q->whereHas('space.building', fn (Builder $b) => $b->where('branch_id', $branch->id)));

        return [
            'active' => (clone $holding)->count(),
            'ending_within_30_days' => (clone $holding)
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $open) => $open->whereNull('terminated_on')->whereDate('ends_on', '<=', $lastDay))
                    ->orWhere(fn (Builder $cut) => $cut->whereNotNull('terminated_on')->whereDate('terminated_on', '<=', $today->addDays(31)->toDateString())))
                ->count(),
        ];
    }
}

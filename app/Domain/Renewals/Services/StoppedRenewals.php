<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use Illuminate\Database\Eloquent\Collection;

/**
 * What stopped renewing and still needs someone: company subscriptions and
 * office leases whose renewal check failed (`renewal_stop_reason`). The
 * renewals screen lists these rows and the dashboard counts them, so the
 * two never disagree.
 */
final class StoppedRenewals
{
    /** @return Collection<int, Membership> */
    public function memberships(?string $reason = null): Collection
    {
        $memberships = Membership::query()
            ->whereNotNull('renewal_stop_reason')
            ->where('owner_type', OwnerType::Company)
            ->when($reason, fn ($q) => $q->where('renewal_stop_reason', $reason))
            ->get();
        // Live or upcoming subscriptions of these companies: an ended stopped
        // one is moot once its company holds another.
        $current = Membership::query()
            ->where('owner_type', OwnerType::Company)
            ->whereIn('owner_id', $memberships->pluck('owner_id'))
            ->where('status', MembershipStatus::Active)
            ->where('current_period_end', '>=', now())
            ->get(['id', 'owner_id'])
            ->groupBy('owner_id');

        return $memberships
            ->reject(fn (Membership $m) => $m->current_period_end->isPast()
                && $current->get($m->owner_id, collect())->contains(fn (Membership $other) => $other->id !== $m->id))
            ->values();
    }

    /** @return Collection<int, OfficeLease> */
    public function leases(?string $reason = null): Collection
    {
        return OfficeLease::query()
            ->whereNotNull('renewal_stop_reason')
            ->when($reason, fn ($q) => $q->where('renewal_stop_reason', $reason))
            ->get();
    }

    public function count(): int
    {
        return $this->memberships()->count() + $this->leases()->count();
    }
}

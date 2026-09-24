<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;

/**
 * The one place "what is this user's active membership" is resolved —
 * extracted out of PackageBrowseService so BookingCreationService and
 * friends share the exact lookup instead of a second copy drifting from it.
 */
class MembershipResolver
{
    /**
     * Personal membership first; else the active membership of a company
     * the user belongs to.
     */
    public function activeFor(User $user): ?Membership
    {
        $membership = Membership::query()
            ->where('owner_type', OwnerType::User)
            ->where('owner_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->where('current_period_end', '>=', now())
            ->with(['plan.planModule', 'plan.balances', 'plan.features', 'plan.discounts', 'plan.resourceGrants.resourceType'])
            ->latest('id')
            ->first();

        if ($membership !== null) {
            return $membership;
        }

        $companyIds = $user->companies()->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return null;
        }

        return Membership::query()
            ->where('owner_type', OwnerType::Company)
            ->whereIn('owner_id', $companyIds)
            ->where('status', MembershipStatus::Active)
            ->where('current_period_end', '>=', now())
            ->with(['plan.planModule', 'plan.balances', 'plan.features', 'plan.discounts', 'plan.resourceGrants.resourceType'])
            ->latest('id')
            ->first();
    }

    /**
     * The same active-membership lookup as activeFor(), generalized to any
     * OwnerType/id pair instead of deriving it from a User's own
     * memberships/companies — used by reception flows that already know
     * which owner (a specific user or company) they're recording
     * consumption against.
     */
    public function activeForOwner(OwnerType $ownerType, int $ownerId): ?Membership
    {
        return Membership::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', MembershipStatus::Active)
            ->where('current_period_end', '>=', now())
            ->with(['plan.planModule', 'plan.balances', 'plan.features', 'plan.discounts', 'plan.resourceGrants.resourceType'])
            ->latest('id')
            ->first();
    }
}

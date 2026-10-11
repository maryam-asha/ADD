<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use Illuminate\Database\Eloquent\Builder;

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
            ->live()
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
            ->live()
            ->with(['plan.planModule', 'plan.balances', 'plan.features', 'plan.discounts', 'plan.resourceGrants.resourceType'])
            ->latest('id')
            ->first();
    }

    /**
     * Whether this user ever held any membership, personal or through one
     * of their companies, whatever its status or period — what tells an
     * expired plan apart from never having had one.
     */
    public function hasHeldAnyFor(User $user): bool
    {
        return $this->heldBy($user)->exists();
    }

    /**
     * Every membership this user holds or held — their own, and those of
     * the companies they belong to now — whatever its status or period.
     *
     * @return Builder<Membership>
     */
    public function heldBy(User $user): Builder
    {
        $companyIds = $user->companies()->pluck('companies.id');

        return Membership::query()->where(function (Builder $query) use ($user, $companyIds) {
            $query->where(fn (Builder $q) => $q->where('owner_type', OwnerType::User)->where('owner_id', $user->id));

            if ($companyIds->isNotEmpty()) {
                $query->orWhere(fn (Builder $q) => $q->where('owner_type', OwnerType::Company)->whereIn('owner_id', $companyIds));
            }
        });
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
            ->live()
            ->with(['plan.planModule', 'plan.balances', 'plan.features', 'plan.discounts', 'plan.resourceGrants.resourceType'])
            ->latest('id')
            ->first();
    }
}

<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Models\OfficeLease;

/**
 * Whether a member works in a leased office, as the door counts it: they
 * belong to a company with door access on, and that company holds an office
 * today. Kitchen orders from such a member go straight to the kitchen
 * (spec decision 1); the branch is the lease's building's.
 */
class OfficeStaff
{
    public function leaseFor(User $member, int $branchId): ?OfficeLease
    {
        $companyIds = $this->companyIds($member);

        if ($companyIds === []) {
            return null;
        }

        return OfficeLease::query()
            ->holdingOn(now()->toDateString())
            ->whereIn('company_id', $companyIds)
            ->whereHas('space.building', fn ($building) => $building->where('branch_id', $branchId))
            ->orderBy('id')
            ->first();
    }

    /** The first lease the member's door access covers, in any branch. */
    public function anyLeaseFor(User $member): ?OfficeLease
    {
        $companyIds = $this->companyIds($member);

        if ($companyIds === []) {
            return null;
        }

        return OfficeLease::query()
            ->holdingOn(now()->toDateString())
            ->whereIn('company_id', $companyIds)
            ->with('space.building')
            ->orderBy('id')
            ->first();
    }

    /** @return list<int> */
    private function companyIds(User $member): array
    {
        return $member->companies()->wherePivot('door_access_enabled', true)->pluck('companies.id')->all();
    }
}

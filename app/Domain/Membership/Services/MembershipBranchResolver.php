<?php

namespace App\Domain\Membership\Services;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\UserBranchMembership;
use App\Domain\Membership\Enums\OwnerType;

/**
 * Which branch's co-working pool a membership holds its seats in, when none
 * was chosen: a company's own branch; else the member's home branch; else
 * the default branch. The one place this rule lives — the backfill migration
 * (2026_09_30_100001) applies the same rule to rows that predate it
 * (docs/decisions/coworking-seat-pool.md).
 */
class MembershipBranchResolver
{
    public function branchFor(OwnerType $ownerType, int $ownerId): ?int
    {
        $branch = match ($ownerType) {
            OwnerType::Company => Company::query()->whereKey($ownerId)->value('branch_id'),
            OwnerType::User => UserBranchMembership::query()
                ->where('user_id', $ownerId)
                ->where('is_home_branch', true)
                ->value('branch_id'),
        };

        return $branch ?? Branch::defaultId();
    }
}

<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;

/**
 * D.8 (docs/decisions/rbac-scoping.md): the scoped capabilities in the
 * app — using a company's shared door code, and managing other members of
 * that same company — checked here rather than through a general
 * scope_type/scope_id system. `Gate::before` in AppServiceProvider still
 * lets `admin` bypass both, same as every other ability.
 */
class CompanyPolicy
{
    /**
     * Is this user a member of this specific company, with the door-access
     * flag on for that membership? Neither condition alone is enough:
     * membership without the flag, or the flag without membership
     * (impossible by construction, but the check stays explicit).
     */
    public function useDoorAccess(User $user, Company $company): bool
    {
        return $company->members()
            ->wherePivot('door_access_enabled', true)
            ->where('users.id', $user->id)
            ->exists();
    }

    /**
     * Is this user a company admin for this specific company? Only a
     * company admin may change another member's `door_access_enabled` or
     * `is_admin` — a regular member cannot, even for themselves, through
     * the member-facing endpoints (Api\V1\Member\CompanyMemberController).
     * Operations/admin manage both fields unconditionally through the
     * admin-dashboard endpoints, via the existing `Gate::before` bypass,
     * independent of this check.
     */
    public function manageMembers(User $user, Company $company): bool
    {
        return $company->members()
            ->wherePivot('is_admin', true)
            ->where('users.id', $user->id)
            ->exists();
    }
}

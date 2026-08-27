<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;

/**
 * D.8 (docs/decisions/rbac-scoping.md): the scoped capabilities in the
 * app — using a company's shared door code, and managing other members of
 * that same company — checked here rather than through a general
 * scope_type/scope_id system. No role gets an automatic bypass on either
 * check: the RBAC permission pilot (docs/decisions/rbac-permission-pilot.md)
 * removed AppServiceProvider's unconditional `Gate::before`, so an
 * admin/operations account needs a real `is_admin`/`door_access_enabled`
 * pivot row on this specific company, same as any other account.
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
     * admin-dashboard endpoints (Api\V1\Admin\CompanyMemberController) —
     * not via a Policy bypass, but because that controller never calls
     * this check at all; its `role:admin|operations` route gate is the
     * only authorization it has, independent of this Policy.
     */
    public function manageMembers(User $user, Company $company): bool
    {
        return $company->members()
            ->wherePivot('is_admin', true)
            ->where('users.id', $user->id)
            ->exists();
    }
}

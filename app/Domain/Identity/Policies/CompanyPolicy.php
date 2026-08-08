<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;

/**
 * D.8 (docs/decisions/rbac-scoping.md): the one scoped capability in the
 * app — using a company's shared door code — checked here rather than
 * through a general scope_type/scope_id system. `Gate::before` in
 * AppServiceProvider still lets `admin` bypass this, same as every other
 * ability.
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
}

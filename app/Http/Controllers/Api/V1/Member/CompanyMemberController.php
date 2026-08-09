<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateCompanyMemberAdminRequest;
use App\Http\Requests\Member\UpdateCompanyMemberDoorAccessRequest;
use App\Http\Resources\CompanyMemberResource;
use Illuminate\Support\Facades\Gate;

/**
 * A company admin manages their own company's members directly — no
 * ops/operations involvement. Adding a brand-new member or removing one
 * stays operations-only (PRD §4: "إضافة أعضائها"); this controller only
 * covers the two flags an existing member's own admin can change
 * (CompanyPolicy::manageMembers, docs/decisions/rbac-scoping.md).
 */
class CompanyMemberController extends Controller
{
    use LogsSensitiveActions;

    public function updateDoorAccess(UpdateCompanyMemberDoorAccessRequest $request, Company $company, User $user): CompanyMemberResource
    {
        Gate::authorize('manageMembers', $company);

        $company->members()->updateExistingPivot($user->id, [
            'door_access_enabled' => $request->validated('door_access_enabled'),
        ]);

        $this->logSensitiveAction('company_member_door_access_changed', $company, [
            'user_id' => $user->id,
            'door_access_enabled' => $request->validated('door_access_enabled'),
        ]);

        $membership = $company->members()->where('users.id', $user->id)->first();

        return new CompanyMemberResource($membership->pivot);
    }

    public function updateAdmin(UpdateCompanyMemberAdminRequest $request, Company $company, User $user): CompanyMemberResource
    {
        Gate::authorize('manageMembers', $company);

        $company->members()->updateExistingPivot($user->id, [
            'is_admin' => $request->validated('is_admin'),
        ]);

        $this->logSensitiveAction('company_member_admin_changed', $company, [
            'user_id' => $user->id,
            'is_admin' => $request->validated('is_admin'),
        ]);

        $membership = $company->members()->where('users.id', $user->id)->first();

        return new CompanyMemberResource($membership->pivot);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateCompanyMemberAdminRequest;
use App\Http\Requests\Member\UpdateCompanyMemberDoorAccessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A company admin manages their own company's members directly — no
 * ops/operations involvement. Adding a brand-new member or removing one
 * stays operations-only (PRD §4: "إضافة أعضائها"); this controller only
 * covers the two flags an existing member's own admin can change
 * (CompanyPolicy::manageMembers, docs/decisions/rbac-scoping.md).
 * For a provider's company, granting admin is routed through a request a
 * reviewer decides instead (docs/decisions/service-providers.md).
 */
class CompanyMemberController extends Controller
{
    use LogsSensitiveActions;

    public function updateDoorAccess(UpdateCompanyMemberDoorAccessRequest $request, Company $company, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $company);

        $company->members()->updateExistingPivot($user->id, [
            'door_access_enabled' => $request->validated('door_access_enabled'),
        ]);

        $this->logSensitiveAction('company_member_door_access_changed', $company, [
            'user_id' => $user->id,
            'door_access_enabled' => $request->validated('door_access_enabled'),
        ]);

        return response()->json(['message' => __('api.member.door_access_updated')]);
    }

    /**
     * For a provider's company, making someone admin is a grant, so it
     * becomes a request a reviewer decides (docs/decisions/service-providers.md)
     * — 202, nothing changed yet. Revoking stays direct: it takes access
     * away. A company ADD deals with directly is unchanged.
     */
    public function updateAdmin(UpdateCompanyMemberAdminRequest $request, Company $company, User $user, ProviderRequestService $requests): JsonResponse
    {
        Gate::authorize('manageMembers', $company);

        if ($company->provider_id !== null && $request->validated('is_admin')) {
            abort_unless($company->members()->where('users.id', $user->id)->exists(), 404);

            $payload = ['user_id' => $user->id];

            if (! $requests->duplicateExists(ProviderRequestType::CompanyAdmin, $company->id, $payload)) {
                $requests->submit($company->provider, $request->user(), ProviderRequestType::CompanyAdmin, $company->id, $payload);
            }

            return response()->json(['message' => __('api.member.company_admin_requested')], 202);
        }

        $company->members()->updateExistingPivot($user->id, [
            'is_admin' => $request->validated('is_admin'),
        ]);

        $this->logSensitiveAction('company_member_admin_changed', $company, [
            'user_id' => $user->id,
            'is_admin' => $request->validated('is_admin'),
        ]);

        return response()->json(['message' => __('api.member.admin_flag_updated')]);
    }
}

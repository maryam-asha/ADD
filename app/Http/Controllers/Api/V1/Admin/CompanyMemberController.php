<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyMemberRequest;
use App\Http\Requests\Admin\UpdateCompanyMemberAdminRequest;
use App\Http\Requests\Admin\UpdateCompanyMemberRequest;
use App\Http\Resources\CompanyMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Adding a member and toggling their door-access flag are both operations
 * capabilities (PRD §4: "إنشاء حسابات الشركات وإضافة أعضائها"), gated by the
 * same role:admin|operations group as the rest of admin.php — no narrower
 * restriction than that.
 */
class CompanyMemberController extends Controller
{
    use LogsSensitiveActions;

    public function index(Company $company): AnonymousResourceCollection
    {
        return CompanyMemberResource::collection($company->members()->get());
    }

    public function store(StoreCompanyMemberRequest $request, Company $company): JsonResponse
    {
        $company->members()->attach($request->validated('user_id'), [
            'door_access_enabled' => $request->boolean('door_access_enabled'),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        $membership = $company->members()->where('users.id', $request->validated('user_id'))->first();

        $this->logSensitiveAction('company_member_added', $company, [
            'user_id' => $request->validated('user_id'),
            'door_access_enabled' => $request->boolean('door_access_enabled'),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        // attach() is a raw insert, not an Eloquent create() — Laravel's
        // usual wasRecentlyCreated-based auto-201 doesn't fire here, so it's
        // set explicitly instead of relying on that implicit detection.
        return (new CompanyMemberResource($membership->pivot))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateDoorAccess(UpdateCompanyMemberRequest $request, Company $company, User $user): JsonResponse
    {
        $company->members()->updateExistingPivot($user->id, [
            'door_access_enabled' => $request->validated('door_access_enabled'),
        ]);

        $this->logSensitiveAction('company_member_door_access_changed', $company, [
            'user_id' => $user->id,
            'door_access_enabled' => $request->validated('door_access_enabled'),
        ]);

        return response()->json(['message' => __('api.admin.company_member_door_access_updated')]);
    }

    /**
     * Operations grants/revokes company-admin status unconditionally
     * (Gate::before bypass) — this is also the only way a company ever
     * gets its first admin, since a company admin can only grant is_admin
     * to an *existing* member (CompanyPolicy::manageMembers).
     */
    public function updateAdmin(UpdateCompanyMemberAdminRequest $request, Company $company, User $user): JsonResponse
    {
        $company->members()->updateExistingPivot($user->id, [
            'is_admin' => $request->validated('is_admin'),
        ]);

        $this->logSensitiveAction('company_member_admin_changed', $company, [
            'user_id' => $user->id,
            'is_admin' => $request->validated('is_admin'),
        ]);

        return response()->json(['message' => __('api.admin.company_member_admin_updated')]);
    }

    public function destroy(Company $company, User $user): Response
    {
        $company->members()->detach($user->id);

        $this->logSensitiveAction('company_member_removed', $company, ['user_id' => $user->id]);

        return response()->noContent();
    }
}

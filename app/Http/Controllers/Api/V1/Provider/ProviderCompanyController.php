<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\MembershipResolver;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\Provider\ProviderCompanyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A provider's client companies (docs/decisions/service-providers.md).
 * Removing an employee needs no approval — it takes access away, never
 * grants it. Adding one is a request (ProviderRequestController).
 */
class ProviderCompanyController extends Controller
{
    use ActsForProvider, LogsSensitiveActions;

    public function __construct(private readonly MembershipResolver $memberships) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = $this->provider($request)->companies()
            ->withCount('members')
            ->orderBy('legal_name')
            ->get()
            ->each(fn (Company $company) => $this->attachMembership($company));

        return ProviderCompanyResource::collection($companies);
    }

    public function show(Request $request, Company $company): ProviderCompanyResource
    {
        $this->authorizeCompany($request, $company);

        $company->load(['members', 'invitations' => fn ($query) => $query->open()])->loadCount('members');
        $this->attachMembership($company);

        return new ProviderCompanyResource($company);
    }

    public function destroyMember(Request $request, Company $company, User $user): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        abort_unless($company->members()->where('users.id', $user->id)->exists(), 404);

        $company->members()->detach($user->id);

        $this->logSensitiveAction('company_member_removed_by_provider', $company, ['user_id' => $user->id]);

        return response()->json(['message' => __('api.provider.member_removed')]);
    }

    private function attachMembership(Company $company): void
    {
        $company->setAttribute('current_membership', $this->memberships->activeForOwner(OwnerType::Company, $company->id));
    }
}

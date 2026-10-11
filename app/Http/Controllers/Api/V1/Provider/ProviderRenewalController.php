<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Domain\Renewals\Services\RenewalSettings;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The provider turns auto-renewal on or off for its companies' subscription
 * and leases. The body is validated only after the subject is found to be
 * the provider's, so another provider's company answers 404 either way.
 */
class ProviderRenewalController extends Controller
{
    use ActsForProvider;

    public function membershipAutoRenew(Request $request, Company $company, MembershipResolver $resolver, MembershipRenewalService $renewals, RenewalSettings $settings): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $membership = $resolver->activeForOwner(OwnerType::Company, $company->id);
        abort_if($membership === null || ! $renewals->isRenewable($membership), 404);
        $request->validate(['auto_renew' => ['required', 'boolean']]);

        $settings->setForMembership($membership, $request->boolean('auto_renew'), $request->user());

        return response()->json(['message' => __('api.renewal.auto_renew_updated')]);
    }

    public function leaseAutoRenew(Request $request, OfficeLease $officeLease, RenewalSettings $settings): JsonResponse
    {
        $this->authorizeLease($request, $officeLease);
        $request->validate(['auto_renew' => ['required', 'boolean']]);

        try {
            $settings->setForLease($officeLease, $request->boolean('auto_renew'), $request->user());
        } catch (RenewalException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.renewal.auto_renew_updated')]);
    }
}

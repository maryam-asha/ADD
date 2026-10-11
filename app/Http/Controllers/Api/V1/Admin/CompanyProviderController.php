<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanyProviderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Moves a company to another provider, or to ADD directly (null). The old
 * provider's pending requests on it are cancelled: they were asked by
 * someone who no longer manages it. Its leases not yet ended move with it;
 * its live and upcoming subscriptions stop auto-renewing.
 */
class CompanyProviderController extends Controller
{
    use LogsSensitiveActions;

    public function update(UpdateCompanyProviderRequest $request, Company $company, ProviderRequestService $requests): JsonResponse
    {
        $before = $company->provider_id;
        $after = $request->validated('provider_id') === null ? null : (int) $request->validated('provider_id');

        [$repointed, $autoRenewOff] = DB::transaction(function () use ($company, $before, $after, $requests) {
            if ($before !== null && $before !== $after) {
                $requests->cancelPending(
                    ProviderRequest::query()->where('provider_id', $before)->where('company_id', $company->id),
                    'company_transferred',
                );
            }

            $company->update(['provider_id' => $after]);

            // The company's leases still running follow it, so the periods
            // still to bill go to the new provider (InvoiceIssuer reads the
            // lease); a company dealing with ADD directly pays itself. Issued
            // invoices stay with whoever they were billed to; an ended lease
            // stays with the provider it ran under.
            $repointed = OfficeLease::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', OfficeLeaseStatus::Ended)
                ->update([
                    'provider_id' => $after,
                    ...($after === null ? ['billed_to' => Payer::Company] : []),
                ]);

            // The subscription's auto-renewal was the old payer's choice; the
            // new provider (or the company, now direct) opts in again.
            // Leases keep theirs: they move with their billing.
            $autoRenewOff = $before === $after ? 0 : Membership::query()
                ->where('owner_type', OwnerType::Company)
                ->where('owner_id', $company->id)
                ->where('status', MembershipStatus::Active)
                ->where('current_period_end', '>=', now())
                ->where('auto_renew', true)
                ->update(['auto_renew' => false, 'renewal_stop_reason' => null]);

            return [$repointed, $autoRenewOff];
        });

        $this->logSensitiveAction('company_provider_changed', $company, [
            'before' => $before,
            'after' => $after,
            'leases_repointed' => $repointed,
            'memberships_auto_renew_off' => $autoRenewOff,
        ]);

        return response()->json(['message' => __('api.admin.company_provider_updated')]);
    }
}

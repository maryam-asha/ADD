<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use Tests\Feature\Leasing\LeasingTestCase;

/** Providers, invoices, leases (LeasingTestCase) plus a subscription assigned through a real approval. */
abstract class RenewalsTestCase extends LeasingTestCase
{
    private ?User $reviewer = null;

    /** An admin account that decides requests, without signing in as it. */
    protected function reviewer(): User
    {
        if ($this->reviewer === null) {
            $this->reviewer = User::factory()->create();
            $this->reviewer->assignRole('admin');
        }

        return $this->reviewer;
    }

    /**
     * A 2-seat, 30-day, 500-point plan assigned to a client company of
     * $provider through an approved plan-assignment request (payer: provider,
     * auto_renew: true, invoice in USD unless $terms says otherwise). The
     * company sits in a default branch with 10 co-working seats.
     */
    protected function assignedMembership(Provider $provider, array $payload = [], ?Company $company = null, ?InvoiceTerms $terms = null): Membership
    {
        if ($company === null) {
            $company = $this->clientCompany($provider, ['branch_id' => $this->branchWithSeats(10)->id]);
        }

        $plan = Plan::factory()->create(['price_points' => 500, 'duration_days' => 30]);
        $request = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'company_id' => $company->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => $plan->id, 'seats' => 2, 'payer' => 'provider', 'auto_renew' => true, ...$payload],
        ]);

        app(ProviderRequestService::class)->approve($request, $this->reviewer(), [], $terms ?? new InvoiceTerms(currency: 'USD'));

        return Membership::query()->findOrFail($request->fresh()->result_id);
    }
}

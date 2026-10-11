<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Services\MembershipRenewalService;

class ProviderRenewalTest extends RenewalsTestCase
{
    public function test_a_provider_turns_a_companys_renewal_off_and_sees_its_state(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $membership->update(['auto_renew' => true, 'renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $company = Company::query()->findOrFail($membership->owner_id);
        $this->providerAccount($provider);

        $this->getJson("/api/v1/provider/companies/{$company->id}")
            ->assertJsonPath('data.membership.auto_renew', true)
            ->assertJsonPath('data.membership.renewal_stop_reason', 'overdue_invoice')
            ->assertJsonPath('data.membership.next_membership', null);

        $this->patchJson("/api/v1/provider/companies/{$company->id}/membership/auto-renew", ['auto_renew' => false])
            ->assertOk()->assertJsonPath('message', __('api.renewal.auto_renew_updated'));
        $this->assertFalse($membership->fresh()->auto_renew);
        $this->assertNull($membership->fresh()->renewal_stop_reason);
    }

    public function test_a_provider_sees_the_next_subscription_once_made(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $next = app(MembershipRenewalService::class)->renew($membership, null);
        $this->providerAccount($provider);

        $this->getJson("/api/v1/provider/companies/{$membership->owner_id}")
            ->assertJsonPath('data.membership.id', $membership->id)
            ->assertJsonPath('data.membership.next_membership.current_period_start', $next->current_period_start->toIso8601String());
    }

    public function test_turning_renewal_off_reaches_the_renewal_already_made(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $next = app(MembershipRenewalService::class)->renew($membership, null);
        $next->update(['renewal_stop_reason' => RenewalStopReason::NoSeats]);
        $this->providerAccount($provider);

        $this->patchJson("/api/v1/provider/companies/{$membership->owner_id}/membership/auto-renew", ['auto_renew' => false])->assertOk();

        $this->assertFalse($membership->fresh()->auto_renew);
        $this->assertFalse($next->fresh()->auto_renew);
        $this->assertNull($next->fresh()->renewal_stop_reason);
    }

    public function test_another_providers_company_is_not_found_before_the_body_is_validated(): void
    {
        $theirs = $this->assignedMembership(Provider::factory()->create());
        $this->providerAccount(Provider::factory()->create());

        $this->patchJson("/api/v1/provider/companies/{$theirs->owner_id}/membership/auto-renew", [])->assertNotFound();
    }

    public function test_another_providers_company_or_lease_is_not_found(): void
    {
        $theirs = $this->assignedMembership(Provider::factory()->create());
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $theirs->owner_id, 'provider_id' => Company::query()->find($theirs->owner_id)->provider_id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $this->providerAccount(Provider::factory()->create());

        $this->patchJson("/api/v1/provider/companies/{$theirs->owner_id}/membership/auto-renew", ['auto_renew' => false])->assertNotFound();
        $this->patchJson("/api/v1/provider/office-leases/{$lease->id}/auto-renew", ['auto_renew' => false])->assertNotFound();
    }

    public function test_a_provider_toggles_its_companys_lease(): void
    {
        $provider = Provider::factory()->create();
        $company = $this->clientCompany($provider);
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $provider->id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $this->providerAccount($provider);

        $this->patchJson("/api/v1/provider/office-leases/{$lease->id}/auto-renew", ['auto_renew' => true])->assertOk();
        $this->assertTrue($lease->fresh()->auto_renew);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertJsonPath('data.auto_renew', true);
    }

    public function test_a_company_with_no_renewable_subscription_is_not_found(): void
    {
        $provider = Provider::factory()->create();
        $company = $this->clientCompany($provider);
        $this->providerAccount($provider);

        $this->patchJson("/api/v1/provider/companies/{$company->id}/membership/auto-renew", ['auto_renew' => true])->assertNotFound();
    }
}

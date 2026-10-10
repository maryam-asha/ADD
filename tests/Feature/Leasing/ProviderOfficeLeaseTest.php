<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;

class ProviderOfficeLeaseTest extends LeasingTestCase
{
    private function lease(Company $company): OfficeLease
    {
        $admin = $this->actingAsAdmin();

        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice()), 'contract_ref' => 'C']),
            $admin,
        );
    }

    public function test_a_provider_reads_its_companies_leases_with_prices_but_no_staff_fields(): void
    {
        $provider = Provider::factory()->create();
        $mine = $this->clientCompany($provider);
        $lease = $this->lease($mine);
        $this->lease($this->clientCompany(Provider::factory()->create()));
        $this->providerAccount($provider);

        $this->getJson('/api/v1/provider/office-leases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $lease->reference);

        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")
            ->assertOk()
            ->assertJsonPath('data.monthly_amount', '500.00')
            ->assertJsonCount(4, 'data.periods')
            ->assertJsonPath('data.periods.0.invoice.amount', '1500.00')
            ->assertJsonMissingPath('data.termination_reason')
            ->assertJsonMissingPath('data.access')
            ->assertJsonMissingPath('data.private_office_request_id');

        $this->getJson("/api/v1/provider/companies/{$mine->id}/office-leases")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_another_providers_lease_or_company_is_not_found(): void
    {
        $provider = Provider::factory()->create();
        $theirs = $this->clientCompany(Provider::factory()->create());
        $lease = $this->lease($theirs);
        $this->providerAccount($provider);

        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertNotFound();
        $this->getJson("/api/v1/provider/companies/{$theirs->id}/office-leases")->assertNotFound();
    }

    public function test_a_transferred_company_takes_its_leases_along(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $company = $this->clientCompany($old);
        $lease = $this->lease($company);
        $company->update(['provider_id' => $new->id]);

        $this->providerAccount($old);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertNotFound();

        $this->providerAccount($new);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertOk();
    }
}

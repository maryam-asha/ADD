<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseDailyRunner;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Spatie\Activitylog\Models\Activity;

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

    /** Transfers through the admin endpoint, as staff do. */
    private function transfer(Company $company, ?Provider $to): void
    {
        $this->actingAsAdmin();
        $this->patchJson("/api/v1/admin/companies/{$company->id}/provider", ['provider_id' => $to?->id])->assertOk();
    }

    public function test_after_a_transfer_the_new_provider_does_not_see_the_old_providers_invoices_and_bills_the_next_period(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $company = $this->clientCompany($old);
        $lease = $this->lease($company);
        $firstInvoice = $lease->periods()->first()->invoice;
        $this->assertSame($old->id, $firstInvoice->provider_id);

        $this->transfer($company, $new);

        $lease->refresh();
        $this->assertSame($new->id, $lease->provider_id);
        // Issued invoices stay with whoever they were billed to.
        $this->assertSame($old->id, $firstInvoice->fresh()->provider_id);
        $this->assertSame(1, Activity::query()->where('description', 'company_provider_changed')->latest('id')->first()->properties['leases_repointed']);

        $this->providerAccount($new);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")
            ->assertOk()
            ->assertJsonPath('data.periods.0.invoice', null);

        $this->travelTo($lease->periods()->where('sequence', 2)->first()->starts_on->setTime(6, 0));
        app(LeaseDailyRunner::class)->run();

        $second = $lease->periods()->where('sequence', 2)->first()->invoice;
        $this->assertSame($new->id, $second->provider_id);

        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")
            ->assertOk()
            ->assertJsonPath('data.periods.0.invoice', null)
            ->assertJsonPath('data.periods.1.invoice.id', $second->id);
    }

    public function test_a_company_moved_to_add_directly_is_billed_itself_from_the_next_period(): void
    {
        $company = $this->clientCompany(Provider::factory()->create());
        $lease = $this->lease($company);
        $this->assertSame(Payer::Provider, $lease->billed_to);

        $this->transfer($company, null);

        $lease->refresh();
        $this->assertNull($lease->provider_id);
        $this->assertSame(Payer::Company, $lease->billed_to);

        $this->travelTo($lease->periods()->where('sequence', 2)->first()->starts_on->setTime(6, 0));
        app(LeaseDailyRunner::class)->run();

        $second = $lease->periods()->where('sequence', 2)->first()->invoice;
        $this->assertNull($second->provider_id);
        $this->assertSame(Payer::Company, $second->billed_to);
    }

    public function test_an_ended_lease_stays_with_the_provider_it_ran_under(): void
    {
        $old = Provider::factory()->create();
        $company = $this->clientCompany($old);
        $lease = $this->lease($company);
        $lease->update(['status' => OfficeLeaseStatus::Ended]);

        $this->transfer($company, Provider::factory()->create());

        $this->assertSame($old->id, $lease->fresh()->provider_id);
    }
}

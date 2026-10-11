<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Providers\Models\Provider;
use Tests\Concerns\BuildsOfficeLeases;

class ProviderOfficeRequestTest extends ProviderTestCase
{
    use BuildsOfficeLeases;

    public function test_a_provider_requests_offices_for_a_client(): void
    {
        $provider = Provider::factory()->create();
        $account = $this->providerAccount($provider);

        $this->postJson('/api/v1/provider/office-requests', ['prospect_name' => 'Acme', 'contact' => '0944444444', 'payer' => 'provider'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.provider_id', $provider->id);

        $request = PrivateOfficeRequest::sole();
        $this->assertSame($account->id, $request->submitted_by);
    }

    public function test_a_provider_lists_only_its_own_office_requests(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        PrivateOfficeRequest::factory()->create(['provider_id' => $provider->id]);
        PrivateOfficeRequest::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        PrivateOfficeRequest::factory()->create();

        $this->getJson('/api/v1/provider/office-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_admin_list_names_the_provider_and_filters_by_it(): void
    {
        $provider = Provider::factory()->create(['name' => 'Platform']);
        PrivateOfficeRequest::factory()->create(['provider_id' => $provider->id]);
        PrivateOfficeRequest::factory()->create();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/private-office-requests?provider_id={$provider->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.name', 'Platform');
    }

    public function test_the_company_contracted_from_a_providers_request_belongs_to_that_provider(): void
    {
        $provider = Provider::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create([
            'provider_id' => $provider->id,
            'payer' => 'provider',
        ]);
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $request->id,
            'legal_name' => 'Acme LLC',
            'contract_ref' => 'C-9',
            ...$this->leasePayload($this->leasableOffice()),
        ])->assertCreated()->assertJsonPath('data.provider_id', $provider->id);

        $this->assertSame($provider->id, Company::sole()->provider_id);
    }
}

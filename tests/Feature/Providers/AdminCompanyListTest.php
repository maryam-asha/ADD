<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Providers\Models\Provider;

class AdminCompanyListTest extends ProviderTestCase
{
    public function test_lists_companies_paginated_with_their_provider(): void
    {
        $provider = Provider::factory()->create(['name' => 'Acme Partners']);
        $this->clientCompany($provider);
        Company::factory()->count(2)->create();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/companies?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/admin/companies?provider_id='.$provider->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.name', 'Acme Partners');
    }

    public function test_filters_direct_companies_status_and_name(): void
    {
        $provider = Provider::factory()->create();
        $this->clientCompany($provider, ['legal_name' => 'Client 100% Co']);
        Company::factory()->create(['legal_name' => 'Direct Co', 'status' => CompanyStatus::Active]);
        Company::factory()->create(['legal_name' => 'Old Direct Co', 'status' => CompanyStatus::Inactive]);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/companies?direct=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.provider', null);

        $this->getJson('/api/v1/admin/companies?direct=1&status=inactive')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.legal_name', 'Old Direct Co');

        // `%` is matched literally, not as a wildcard.
        $this->getJson('/api/v1/admin/companies?search='.urlencode('100%'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.legal_name', 'Client 100% Co');
    }

    public function test_provider_and_direct_filters_are_exclusive(): void
    {
        $provider = Provider::factory()->create();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/companies?direct=1&provider_id='.$provider->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_id');
    }
}

<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Providers\Models\Provider;

/** The list shapes the dashboard's pickers and filters rely on. */
class DashboardListsTest extends ProviderTestCase
{
    public function test_all_returns_every_company_unpaginated_for_pickers(): void
    {
        Company::factory()->count(20)->create();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/companies?all=1')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonMissingPath('meta');

        $this->getJson('/api/v1/admin/companies')->assertOk()->assertJsonCount(15, 'data');
        $this->getJson('/api/v1/admin/companies?all=1&per_page=5')->assertUnprocessable()->assertJsonValidationErrors('all');
    }

    public function test_office_requests_filter_by_status_and_search_newest_first(): void
    {
        $company = Company::factory()->create(['legal_name' => 'Orbit Labs']);
        $old = PrivateOfficeRequest::factory()->create(['prospect_name' => 'Nova Studio', 'contact' => 'nova@example.com']);
        $quoted = PrivateOfficeRequest::factory()->quoted()->create(['prospect_name' => 'Delta 100%']);
        $existing = PrivateOfficeRequest::factory()->create(['prospect_name' => 'Annex', 'company_id' => $company->id]);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/private-office-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $existing->id)
            ->assertJsonPath('data.2.id', $old->id);

        $this->getJson('/api/v1/admin/private-office-requests?status='.PrivateOfficeRequestStatus::Quoted->value)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $quoted->id);

        // The prospect, the contact, and the existing company's name; % is literal.
        $this->getJson('/api/v1/admin/private-office-requests?search=nova@')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/admin/private-office-requests?search=orbit')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $existing->id);
        $this->getJson('/api/v1/admin/private-office-requests?search='.urlencode('100%'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/private-office-requests?search='.urlencode('%'))->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/private-office-requests?status=signed')->assertUnprocessable();
    }

    public function test_a_provider_is_created_with_payment_terms_and_listed_with_its_account_count(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/admin/providers', ['name' => 'Kappa', 'contract_ref' => 'K-1', 'payment_terms_days' => 45])
            ->assertCreated()
            ->assertJsonPath('data.payment_terms_days', 45)
            ->json('data.id');

        $this->providerAccount(Provider::query()->findOrFail($id));
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/providers')
            ->assertOk()
            ->assertJsonPath('data.0.users_count', 1)
            ->assertJsonPath('data.0.companies_count', 0);
    }
}

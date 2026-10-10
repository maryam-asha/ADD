<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;

class ContractLeaseEndpointsTest extends LeasingTestCase
{
    public function test_admin_creates_a_company_with_its_lease(): void
    {
        $this->actingAsAdmin();
        $office = $this->leasableOffice();
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $response = $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $request->id,
            'legal_name' => 'Acme LLC',
            'contract_ref' => 'C-3001',
            ...$this->leasePayload($office),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.legal_name', 'Acme LLC')
            ->assertJsonPath('data.branch_id', $office->building->branch_id)
            ->assertJsonPath('data.office_leases.0.space.id', $office->id)
            ->assertJsonPath('data.office_leases.0.monthly_amount', '500.00')
            ->assertJsonCount(4, 'data.office_leases.0.periods')
            ->assertJsonPath('data.office_leases.0.periods.0.invoice.amount', '1500.00')
            ->assertJsonPath('data.office_leases.0.periods.1.invoice', null);
    }

    public function test_the_lease_from_a_request_needs_its_permission(): void
    {
        $company = Company::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]);
        $body = ['contract_ref' => 'C-3002', ...$this->leasePayload($this->leasableOffice())];

        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);
        $this->postJson("/api/v1/admin/private-office-requests/{$request->id}/lease", $body)->assertForbidden();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/private-office-requests/{$request->id}/lease", $body)
            ->assertCreated()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.billed_to', 'company')
            ->assertJsonPath('data.access.status', 'none')
            ->assertJsonPath('data.access.warning', null);
    }

    public function test_lease_fields_are_validated(): void
    {
        $this->actingAsAdmin();
        $company = Company::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]);
        $office = $this->leasableOffice();
        $url = "/api/v1/admin/private-office-requests/{$request->id}/lease";

        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['duration_months' => 10, 'billing_cycle_months' => 3])])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_cycle_months');
        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['currency' => 'SYP', 'monthly_amount' => '100.50'])])
            ->assertUnprocessable()->assertJsonValidationErrors('monthly_amount');
        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['monthly_amount' => '0'])])
            ->assertUnprocessable()->assertJsonValidationErrors('monthly_amount');
        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['starts_on' => '10/01/2026'])])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson($url, $this->leasePayload($office))
            ->assertUnprocessable()->assertJsonValidationErrors('contract_ref');
    }

    public function test_a_taken_office_answers_409_with_the_message(): void
    {
        $this->actingAsAdmin();
        $office = $this->leasableOffice();
        $first = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]);
        $second = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]);

        $this->postJson("/api/v1/admin/private-office-requests/{$first->id}/lease", ['contract_ref' => 'A', ...$this->leasePayload($office)])->assertCreated();
        $this->postJson("/api/v1/admin/private-office-requests/{$second->id}/lease", ['contract_ref' => 'B', ...$this->leasePayload($office)])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.lease.office_taken'));
    }

    public function test_admin_files_an_office_request_for_an_existing_company(): void
    {
        $this->actingAsAdmin();
        $company = Company::factory()->create();

        $this->postJson('/api/v1/admin/private-office-requests', [
            'prospect_name' => 'Acme second office',
            'contact' => '+963944444444',
            'company_id' => $company->id,
        ])->assertCreated()->assertJsonPath('data.company_id', $company->id);
    }

    public function test_a_provider_requests_an_office_for_one_of_its_companies(): void
    {
        $provider = Provider::factory()->create();
        $own = $this->clientCompany($provider);
        $inactive = $this->clientCompany($provider);
        $inactive->update(['status' => CompanyStatus::Inactive]);
        $someoneElses = $this->clientCompany(Provider::factory()->create());
        $this->providerAccount($provider);
        $body = ['prospect_name' => 'Second office', 'contact' => '+963944444444', 'payer' => 'provider'];

        $this->postJson('/api/v1/provider/office-requests', [...$body, 'company_id' => $own->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $own->id)
            ->assertJsonPath('data.payer', 'provider');
        $this->postJson('/api/v1/provider/office-requests', [...$body, 'company_id' => $someoneElses->id])->assertNotFound();
        $this->postJson('/api/v1/provider/office-requests', [...$body, 'company_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.provider.company_unavailable'));
        $this->postJson('/api/v1/provider/office-requests', ['prospect_name' => 'New client', 'contact' => '+963944444444'])
            ->assertUnprocessable()->assertJsonValidationErrors('payer');
    }
}

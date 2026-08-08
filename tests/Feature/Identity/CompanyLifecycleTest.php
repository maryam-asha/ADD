<?php

namespace Tests\Feature\Identity;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * PRD §5.1/§5.3: companies are created exclusively by operations, after a
 * contract is signed — the pipeline is request -> quote -> signed contract.
 */
class CompanyLifecycleTest extends IdentityTestCase
{
    public function test_operations_creates_a_company_from_a_quoted_request_and_closes_the_pipeline(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $poRequest = PrivateOfficeRequest::factory()->quoted()->create();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $poRequest->id,
            'legal_name' => 'ACME LLC',
            'contract_ref' => 'C-1001',
            'branch_id' => $branch->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.legal_name', 'ACME LLC');
        $response->assertJsonPath('data.status', 'active');

        $poRequest->refresh();
        $this->assertSame(PrivateOfficeRequestStatus::Contracted, $poRequest->status);
        $this->assertSame('C-1001', $poRequest->contract_ref);
        $this->assertNotNull($poRequest->converted_company_id);
    }

    public function test_creating_a_company_from_a_request_that_is_not_yet_quoted_fails_validation(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $poRequest = PrivateOfficeRequest::factory()->create(); // still 'requested', no quote issued
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $poRequest->id,
            'legal_name' => 'ACME LLC',
            'contract_ref' => 'C-1002',
            'branch_id' => $branch->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('private_office_request_id');

        $poRequest->refresh();
        $this->assertSame(PrivateOfficeRequestStatus::Requested, $poRequest->status);
    }

    public function test_operations_can_deactivate_a_company(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();

        $response = $this->patchJson("/api/v1/admin/companies/{$company->id}/status", [
            'status' => 'inactive',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'inactive');
        $this->assertSame(CompanyStatus::Inactive, $company->refresh()->status);
    }
}

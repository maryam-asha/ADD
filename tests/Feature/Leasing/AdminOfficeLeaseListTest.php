<?php

namespace Tests\Feature\Leasing;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;

class AdminOfficeLeaseListTest extends LeasingTestCase
{
    private function lease(Company $company, array $overrides = [], bool $withLock = true, ?Branch $branch = null): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'payer' => Payer::Company]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice($branch, $withLock)), 'contract_ref' => 'C', ...$overrides]),
            auth()->user(),
        );
    }

    public function test_lists_and_filters_leases(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();
        $client = Company::factory()->create(['provider_id' => $provider->id]);
        $direct = Company::factory()->create();
        $branch = Branch::factory()->create();

        $this->lease($client, ['duration_months' => 1, 'billing_cycle_months' => 1]);
        $this->lease($direct, branch: $branch);
        $ended = $this->lease($direct);
        $ended->update(['status' => OfficeLeaseStatus::Ended]);

        $this->getJson('/api/v1/admin/office-leases')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/admin/office-leases?provider_id='.$provider->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider.id', $provider->id);
        $this->getJson('/api/v1/admin/office-leases?company_id='.$direct->id)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/office-leases?branch_id='.$branch->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/office-leases?status=ended')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/office-leases?ending_within_days=40')->assertJsonCount(1, 'data')->assertJsonPath('data.0.company.id', $client->id);
        $this->getJson('/api/v1/admin/office-leases?status=bogus')->assertUnprocessable();
    }

    public function test_detail_shows_periods_invoices_and_the_lock_warning_but_never_a_passcode(): void
    {
        $this->fakeTtlock();
        $this->actingAsAdmin();
        $withLock = $this->lease(Company::factory()->create(), ['starts_on' => now()->toDateString()]);
        $withoutLock = $this->lease(Company::factory()->create(), withLock: false);

        $response = $this->getJson("/api/v1/admin/office-leases/{$withLock->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', $withLock->reference)
            ->assertJsonCount(4, 'data.periods')
            ->assertJsonPath('data.periods.0.invoice.status', 'unpaid')
            ->assertJsonPath('data.access.status', 'active')
            ->assertJsonPath('data.access.warning', null);
        $this->assertStringNotContainsString('112233', $response->getContent());
        $this->assertStringNotContainsString('passcode', $response->getContent());

        $this->getJson("/api/v1/admin/office-leases/{$withoutLock->id}")
            ->assertJsonPath('data.access.status', 'none')
            ->assertJsonPath('data.access.warning', 'no_lock');
    }

    public function test_the_company_detail_carries_its_leases(): void
    {
        $this->actingAsAdmin();
        $company = Company::factory()->create();
        $lease = $this->lease($company);

        $this->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.office_leases.0.reference', $lease->reference);
    }

    public function test_viewing_needs_its_permission(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $this->getJson('/api/v1/admin/office-leases')->assertForbidden();
    }
}

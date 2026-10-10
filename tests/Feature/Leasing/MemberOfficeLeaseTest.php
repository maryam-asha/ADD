<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use Laravel\Sanctum\Sanctum;

class MemberOfficeLeaseTest extends LeasingTestCase
{
    private function lease(Company $company): OfficeLease
    {
        $admin = $this->actingAsAdmin();

        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice()), 'contract_ref' => 'C']),
            $admin,
        );
    }

    private function member(Company $company, bool $isAdmin): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        $company->members()->attach($user->id, ['is_admin' => $isAdmin, 'door_access_enabled' => true]);

        return $user;
    }

    public function test_the_company_admin_sees_office_and_dates_without_prices(): void
    {
        $company = Company::factory()->create();
        $lease = $this->lease($company);
        Sanctum::actingAs($this->member($company, true), ['member-app']);

        $response = $this->getJson("/api/v1/member/companies/{$company->id}/office-leases")
            ->assertOk()
            ->assertJsonPath('data.0.reference', $lease->reference)
            ->assertJsonPath('data.0.starts_on', $lease->starts_on->toDateString())
            ->assertJsonPath('data.0.ends_on', $lease->ends_on->toDateString())
            ->assertJsonPath('data.0.space.id', $lease->space_id)
            ->assertJsonMissingPath('data.0.monthly_amount')
            ->assertJsonMissingPath('data.0.periods')
            ->assertJsonMissingPath('data.0.currency');
        $this->assertStringNotContainsString('1500', $response->getContent());
    }

    public function test_a_plain_member_or_an_outsider_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->lease($company);

        Sanctum::actingAs($this->member($company, false), ['member-app']);
        $this->getJson("/api/v1/member/companies/{$company->id}/office-leases")->assertForbidden();

        Sanctum::actingAs($this->member(Company::factory()->create(), true), ['member-app']);
        $this->getJson("/api/v1/member/companies/{$company->id}/office-leases")->assertForbidden();
    }
}

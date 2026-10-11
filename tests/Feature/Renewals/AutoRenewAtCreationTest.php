<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;

class AutoRenewAtCreationTest extends RenewalsTestCase
{
    public function test_the_provider_chooses_auto_renew_when_asking_for_a_plan(): void
    {
        $provider = Provider::factory()->create();
        $renewing = $this->assignedMembership($provider, ['auto_renew' => true]);
        $branch = $renewing->branch_id;
        $plain = $this->assignedMembership($provider, ['auto_renew' => false], $this->clientCompany($provider, ['branch_id' => $branch]));

        $this->assertTrue($renewing->auto_renew);
        $this->assertFalse($plain->auto_renew);
    }

    public function test_the_provider_request_accepts_auto_renew(): void
    {
        $provider = Provider::factory()->create();
        $company = $this->clientCompany($provider);
        $plan = Plan::factory()->create();
        $this->providerAccount($provider);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id,
            'seats' => 2, 'payer' => 'provider', 'auto_renew' => true,
        ])->assertCreated();

        $this->assertTrue(ProviderRequest::query()->latest('id')->first()->payload['auto_renew']);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id,
            'seats' => 2, 'payer' => 'provider', 'auto_renew' => 'sometimes',
        ])->assertUnprocessable()->assertJsonValidationErrors('auto_renew');
    }

    public function test_staff_choose_auto_renew_when_making_a_lease(): void
    {
        $this->actingAsAdmin();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]);

        $this->postJson("/api/v1/admin/private-office-requests/{$request->id}/lease", [
            'contract_ref' => 'C', ...$this->leasePayload($this->leasableOffice()), 'auto_renew' => true,
        ])->assertCreated();

        $this->assertTrue(OfficeLease::query()->sole()->auto_renew);
    }
}

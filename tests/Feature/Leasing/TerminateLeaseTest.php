<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use Laravel\Sanctum\Sanctum;

class TerminateLeaseTest extends LeasingTestCase
{
    private function lease(Space $office, array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($office, ['billing_cycle_months' => 1, 'duration_months' => 6]), 'contract_ref' => 'C', ...$overrides]),
            auth()->user(),
        );
    }

    /** @return list<string> */
    private function statuses(OfficeLease $lease): array
    {
        return $lease->periods()->get()->map(fn ($p) => $p->status->value)->all();
    }

    public function test_terminating_today_closes_the_door_and_cancels_what_is_left(): void
    {
        $this->fakeTtlock();
        $this->actingAsAdmin();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->subMonthsNoOverflow(2)->toDateString()]);
        $this->assertSame(AccessGrantStatus::Activated, $lease->accessGrants()->sole()->status);

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/terminate", [
            'terminated_on' => now()->toDateString(),
            'reason' => 'Contract clause 7',
        ])->assertOk()->assertJsonPath('message', __('api.lease.terminated'));

        $lease->refresh();
        $this->assertSame(OfficeLeaseStatus::Terminated, $lease->status);
        $this->assertSame('Contract clause 7', $lease->termination_reason);
        $this->assertSame(['invoiced', 'invoiced', 'invoiced', 'cancelled', 'cancelled', 'cancelled'], $this->statuses($lease));
        $this->assertSame(AccessGrantStatus::Revoked, $lease->accessGrants()->sole()->status);
    }

    public function test_terminating_on_a_future_date_keeps_the_door_and_the_periods_before_it(): void
    {
        $this->fakeTtlock();
        $this->actingAsAdmin();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->toDateString()]);

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/terminate", [
            'terminated_on' => now()->addMonthsNoOverflow(2)->toDateString(),
            'reason' => 'Moving out',
        ])->assertOk();

        $this->assertSame(['invoiced', 'upcoming', 'cancelled', 'cancelled', 'cancelled', 'cancelled'], $this->statuses($lease));
        $this->assertSame(AccessGrantStatus::Activated, $lease->accessGrants()->sole()->status);
    }

    public function test_terminating_on_the_start_date_frees_the_office_from_that_date(): void
    {
        $this->actingAsAdmin();
        $office = $this->leasableOffice();
        $lease = $this->lease($office);

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/terminate", [
            'terminated_on' => $lease->starts_on->toDateString(),
            'reason' => 'Signed by mistake',
        ])->assertOk();

        $this->assertSame(['invoiced', 'cancelled', 'cancelled', 'cancelled', 'cancelled', 'cancelled'], $this->statuses($lease));
        $this->assertFalse($lease->fresh()->grantsAccessOn($lease->starts_on->toDateString()));
        $this->assertTrue($this->lease($office)->exists, 'The office can be leased again from the same start date.');
    }

    public function test_refusals(): void
    {
        $this->actingAsAdmin();
        $lease = $this->lease($this->leasableOffice());
        $url = "/api/v1/admin/office-leases/{$lease->id}/terminate";

        $this->postJson($url, ['terminated_on' => $lease->starts_on->subDay()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonPath('message', __('api.lease.termination_out_of_range'));
        $this->postJson($url, ['terminated_on' => $lease->ends_on->addDay()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable();
        $this->postJson($url, ['terminated_on' => $lease->starts_on->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson($url, ['terminated_on' => $lease->ends_on->toDateString(), 'reason' => 'x'])->assertOk();
        $this->postJson($url, ['terminated_on' => $lease->ends_on->toDateString(), 'reason' => 'again'])
            ->assertStatus(409)->assertJsonPath('message', __('api.lease.not_active'));

        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);
        $this->postJson($url, ['terminated_on' => $lease->ends_on->toDateString(), 'reason' => 'x'])->assertForbidden();
    }
}

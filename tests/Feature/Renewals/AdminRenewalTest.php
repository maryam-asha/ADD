<?php

namespace Tests\Feature\Renewals;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Services\MembershipRenewalService;
use Laravel\Sanctum\Sanctum;

class AdminRenewalTest extends RenewalsTestCase
{
    private function lease(): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false), ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
    }

    public function test_staff_renew_a_stopped_subscription_past_an_overdue_invoice(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $membership->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")
            ->assertOk()->assertJsonPath('message', __('api.renewal.renewed'));

        $this->assertSame(1, Membership::query()->where('renewed_from_id', $membership->id)->count());
        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")->assertStatus(409);
    }

    public function test_a_manual_renewal_still_needs_seats(): void
    {
        $membership = $this->assignedMembership(Provider::factory()->create());
        Space::query()->whereHas('building', fn ($q) => $q->where('branch_id', $membership->branch_id))->update(['capacity' => 1]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.renewal.refused', ['reason' => RenewalStopReason::NoSeats->label()]));
    }

    public function test_staff_extend_a_lease_and_toggle_auto_renew(): void
    {
        $lease = $this->lease();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/renew")->assertOk();
        $this->assertSame(1, $lease->fresh()->renewals_count);

        $lease->update(['auto_renew' => true, 'renewal_stop_reason' => RenewalStopReason::OfficeTaken]);
        $this->patchJson("/api/v1/admin/office-leases/{$lease->id}/auto-renew", ['auto_renew' => false])
            ->assertOk()->assertJsonPath('message', __('api.renewal.auto_renew_updated'));
        $this->assertFalse($lease->fresh()->auto_renew);
        $this->assertNull($lease->fresh()->renewal_stop_reason);

        $this->getJson("/api/v1/admin/office-leases/{$lease->id}")
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.renewals_count', 1);
    }

    public function test_toggling_a_subscription_not_from_a_provider_is_refused(): void
    {
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => Company::factory()->create()->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/memberships/{$membership->id}/auto-renew", ['auto_renew' => true])
            ->assertUnprocessable()->assertJsonPath('message', __('api.renewal.not_renewable'));
    }

    public function test_the_stopped_list_filters_by_type_reason_and_provider(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $membership->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $lease = $this->lease();
        $lease->update(['renewal_stop_reason' => RenewalStopReason::OfficeTaken]);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/renewals/stopped')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/renewals/stopped?type=lease')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'lease')
            ->assertJsonPath('data.0.reason', 'office_taken')
            ->assertJsonPath('data.0.reason_label', RenewalStopReason::OfficeTaken->label());
        $this->getJson('/api/v1/admin/renewals/stopped?reason=overdue_invoice')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'membership')
            ->assertJsonPath('data.0.provider.id', $provider->id)
            ->assertJsonPath('data.0.has_ended', false);
        $this->getJson('/api/v1/admin/renewals/stopped?provider_id='.$provider->id)->assertJsonCount(1, 'data');
    }

    public function test_a_stopped_subscription_superseded_by_a_newer_one_cannot_be_renewed_and_leaves_the_list(): void
    {
        $provider = Provider::factory()->create();
        $old = $this->assignedMembership($provider);
        $old->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $this->travel(40)->days();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/renewals/stopped?type=membership')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_ended', true);

        $this->assignedMembership($provider, company: Company::query()->findOrFail($old->owner_id));

        $this->postJson("/api/v1/admin/memberships/{$old->id}/renew")
            ->assertStatus(409)->assertJsonPath('message', __('api.renewal.superseded'));
        $this->assertSame(0, Membership::query()->where('renewed_from_id', $old->id)->count());
        $this->getJson('/api/v1/admin/renewals/stopped?type=membership')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_the_stopped_list_rejects_a_page_below_one(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/renewals/stopped?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_a_provider_transfer_turns_off_the_companys_subscription_auto_renew_but_not_its_leases(): void
    {
        $old = Provider::factory()->create();
        $current = $this->assignedMembership($old);
        $next = app(MembershipRenewalService::class)->renew($current, null);
        $current->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $current->owner_id, 'provider_id' => $old->id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false), ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $lease->update(['auto_renew' => true]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/companies/{$current->owner_id}/provider", ['provider_id' => Provider::factory()->create()->id])->assertOk();

        $this->assertFalse($current->fresh()->auto_renew);
        $this->assertNull($current->fresh()->renewal_stop_reason);
        $this->assertFalse($next->fresh()->auto_renew);
        $this->assertTrue($lease->fresh()->auto_renew);
    }

    public function test_it_all_needs_the_permission(): void
    {
        $membership = $this->assignedMembership(Provider::factory()->create());
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $this->getJson('/api/v1/admin/renewals/stopped')->assertForbidden();
        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")->assertForbidden();
    }
}

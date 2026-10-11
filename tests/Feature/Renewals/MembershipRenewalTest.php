<?php

namespace Tests\Feature\Renewals;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\MembershipRenewalService;

class MembershipRenewalTest extends RenewalsTestCase
{
    private function service(): MembershipRenewalService
    {
        return app(MembershipRenewalService::class);
    }

    private function assertRefused(callable $action, RenewalStopReason $reason): void
    {
        try {
            $action();
            $this->fail("Expected the renewal to be refused with {$reason->value}.");
        } catch (RenewalRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_the_next_subscription_starts_where_this_one_ends_and_is_invoiced(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->assignedMembership($provider);
        $current->update(['renewal_stop_reason' => RenewalStopReason::NoSeats]);

        $renewal = $this->service()->renew($current, null);

        $this->assertSame($current->current_period_end->timestamp, $renewal->current_period_start->timestamp);
        $this->assertSame($current->current_period_end->copy()->addDays(30)->timestamp, $renewal->current_period_end->timestamp);
        $this->assertSame([$current->plan_id, 2, $current->branch_id], [$renewal->plan_id, $renewal->seats, $renewal->branch_id]);
        $this->assertTrue($renewal->auto_renew);
        $this->assertSame($current->id, $renewal->renewed_from_id);
        $this->assertNull($current->fresh()->renewal_stop_reason);
        $this->assertFalse($renewal->isLive());

        $invoice = Invoice::query()->where('membership_id', $renewal->id)->sole();
        $this->assertSame(1000, $invoice->points);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(Payer::Provider, $invoice->billed_to);
        $this->assertSame($provider->id, $invoice->provider_id);
    }

    public function test_the_renewal_charges_the_current_plan_price_in_the_first_invoices_currency(): void
    {
        $this->syp();
        $current = $this->assignedMembership(Provider::factory()->create(), terms: new InvoiceTerms(currency: 'SYP'));
        $current->plan->update(['price_points' => 700]);

        $renewal = $this->service()->renew($current, null);

        $invoice = Invoice::query()->where('membership_id', $renewal->id)->sole();
        $this->assertSame(1400, $invoice->points);
        $this->assertSame('SYP', $invoice->currency);
    }

    public function test_the_chain_keeps_renewing_from_the_last_link(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());

        $second = $this->service()->renew($current, null);
        $third = $this->service()->renew($second, null);

        $this->assertSame($second->id, $third->renewed_from_id);
        $this->assertSame($second->current_period_end->timestamp, $third->current_period_start->timestamp);
        $this->assertTrue($this->service()->isRenewable($third));
    }

    public function test_a_subscription_is_renewed_once(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $this->service()->renew($current, null);

        try {
            $this->service()->renew($current->fresh(), null);
            $this->fail('Expected already_renewed.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.already_renewed', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(1, Membership::query()->where('renewed_from_id', $current->id)->count());
        $this->assertSame(1, Invoice::query()->whereNotNull('membership_id')->count());
    }

    public function test_an_overdue_invoice_of_the_payer_stops_the_renewal_unless_staff_override(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->assignedMembership($provider);
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);

        $this->assertRefused(fn () => $this->service()->renew($current, null), RenewalStopReason::OverdueInvoice);
        $this->assertSame(0, Membership::query()->where('renewed_from_id', $current->id)->count());

        $this->assertTrue($this->service()->renew($current, $this->reviewer(), checkOverdue: false)->exists);
    }

    public function test_an_overdue_invoice_of_another_payer_does_not_stop_it(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->assignedMembership($provider);
        $company = Company::query()->findOrFail($current->owner_id);
        Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $company->id, 'billed_to' => Payer::Company, 'due_at' => now()->subDay()->toDateString()]);

        $this->assertTrue($this->service()->renew($current, null)->exists);
    }

    public function test_refusals(): void
    {
        $provider = Provider::factory()->create();

        $inactiveCompany = $this->assignedMembership($provider);
        Company::query()->whereKey($inactiveCompany->owner_id)->update(['status' => CompanyStatus::Inactive]);
        $this->assertRefused(fn () => $this->service()->renew($inactiveCompany, null), RenewalStopReason::CompanyInactive);

        $inactivePlan = $this->assignedMembership($provider, company: $this->clientCompany($provider, ['branch_id' => $inactiveCompany->branch_id]));
        $inactivePlan->plan->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->service()->renew($inactivePlan, null), RenewalStopReason::PlanInactive);

        $noSeats = $this->assignedMembership($provider, company: $this->clientCompany($provider, ['branch_id' => $inactiveCompany->branch_id]));
        Space::query()->whereHas('building', fn ($q) => $q->where('branch_id', $noSeats->branch_id))->update(['capacity' => 1]);
        $this->assertRefused(fn () => $this->service()->renew($noSeats, null), RenewalStopReason::NoSeats);
    }

    public function test_a_subscription_not_assigned_by_a_provider_is_not_renewable(): void
    {
        $company = Company::factory()->create();
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->assertFalse($this->service()->isRenewable($membership));
        $this->expectException(RenewalException::class);

        $this->service()->renew($membership, null);
    }

    public function test_after_a_transfer_the_renewal_belongs_to_the_new_provider(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $current = $this->assignedMembership($old);
        Company::query()->whereKey($current->owner_id)->update(['provider_id' => $new->id]);

        $renewal = $this->service()->renew($current, null);

        $invoice = Invoice::query()->where('membership_id', $renewal->id)->sole();
        $this->assertSame($new->id, $invoice->provider_id);
        $this->assertSame(Payer::Provider, $invoice->billed_to);
    }

    public function test_a_free_plan_renews_without_an_invoice(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $current->plan->update(['price_points' => 0]);

        $renewal = $this->service()->renew($current, null);

        $this->assertTrue($renewal->exists);
        $this->assertSame(0, Invoice::query()->where('membership_id', $renewal->id)->count());
    }

    public function test_a_subscription_that_already_ended_renews_from_now(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $this->travel(40)->days();

        $renewal = $this->service()->renew($current->fresh(), $this->reviewer(), checkOverdue: false);

        $this->assertEqualsWithDelta(now()->timestamp, $renewal->current_period_start->timestamp, 2);
        $this->assertTrue($renewal->isLive());
    }
}

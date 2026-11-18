<?php

namespace Tests\Feature\Renewals;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Notification;

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

    public function test_an_automatic_renewal_rechecks_auto_renew_under_the_lock(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $this->assertTrue($current->auto_renew);
        Membership::query()->whereKey($current->id)->update(['auto_renew' => false]);

        try {
            $this->service()->renew($current, null, automatic: true);
            $this->fail('Expected not_renewable.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.not_renewable', $e->messageKey);
        }

        $this->assertSame(0, Membership::query()->where('renewed_from_id', $current->id)->count());
    }

    public function test_a_manual_renewal_keeps_the_subscriptions_auto_renew_choice(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create(), ['auto_renew' => false]);
        $this->assertFalse($current->auto_renew);

        $renewal = $this->service()->renew($current, $this->reviewer(), checkOverdue: false);

        $this->assertFalse($renewal->fresh()->auto_renew);
    }

    public function test_a_stale_stopped_subscription_is_not_renewed_over_a_newer_one(): void
    {
        $provider = Provider::factory()->create();
        $old = $this->assignedMembership($provider);
        $old->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $this->travel(40)->days();
        $company = Company::query()->findOrFail($old->owner_id);
        $this->assignedMembership($provider, company: $company);

        try {
            $this->service()->renew($old->fresh(), $this->reviewer(), checkOverdue: false);
            $this->fail('Expected superseded.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.superseded', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(0, Membership::query()->where('renewed_from_id', $old->id)->count());
    }

    public function test_a_dedicated_desk_subscription_renews_holding_its_desks(): void
    {
        [$current, $desk] = $this->deskSubscription();

        $renewal = $this->service()->renew($current, null);

        $this->assertSame([$desk->id], $renewal->fresh()->seatDesks->pluck('id')->all());
    }

    public function test_a_dedicated_desk_taken_in_the_next_period_renews_without_desks_and_tells_staff(): void
    {
        Notification::fake();
        [$current, $desk] = $this->deskSubscription();
        $other = Membership::factory()->create([
            'plan_id' => $current->plan_id,
            'owner_type' => OwnerType::Company,
            'owner_id' => Company::factory()->create()->id,
            'branch_id' => $current->branch_id,
            'current_period_start' => $current->current_period_end,
            'current_period_end' => $current->current_period_end->copy()->addDays(30),
        ]);
        $other->seatDesks()->sync([$desk->id]);

        $renewal = $this->service()->renew($current, null);

        $this->assertSame([], $renewal->fresh()->seatDesks->pluck('id')->all());
        Notification::assertSentTo(
            $this->reviewer(),
            GeneralNotification::class,
            fn (GeneralNotification $n) => $n->toArray($this->reviewer())['template_key'] === 'membership.desk_needed',
        );
    }

    /** @return array{0: Membership, 1: SeatDesk} */
    private function deskSubscription(): array
    {
        $plan = Plan::factory()->dedicatedDesk()->create(['price_points' => 500, 'duration_days' => 30]);
        $current = $this->assignedMembership(Provider::factory()->create(), ['plan_id' => $plan->id, 'seats' => 1]);
        $coSpace = Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $current->branch_id])->id,
            'space_type' => 'co_space',
        ]);
        $desk = SeatDesk::factory()->create(['space_id' => $coSpace->id]);
        app(DedicatedDeskService::class)->assign($current->load('plan'), [$desk->id]);

        return [$current->fresh(), $desk];
    }
}

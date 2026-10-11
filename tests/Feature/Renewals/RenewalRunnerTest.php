<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Services\RenewalRunner;

class RenewalRunnerTest extends RenewalsTestCase
{
    private function runRenewals(): array
    {
        return app(RenewalRunner::class)->run();
    }

    /** The assignment/rent invoices fall due while the test travels; settle them so only the invoices a test adds can be overdue. */
    private function settleInvoices(): void
    {
        Invoice::query()->get()->each(fn (Invoice $invoice) => $invoice->update(['status' => InvoiceStatus::Paid, 'paid_amount' => $invoice->amount]));
    }

    private function renewable(Provider $provider): Membership
    {
        $membership = $this->assignedMembership($provider);
        $membership->update(['auto_renew' => true]);
        $this->settleInvoices();

        return $membership->fresh();
    }

    public function test_a_subscription_renews_inside_the_window_and_only_once(): void
    {
        $current = $this->renewable(Provider::factory()->create());

        $this->travelTo($current->current_period_end->copy()->subDays(8));
        $this->assertSame(0, $this->runRenewals()['memberships_renewed']);

        $this->travelTo($current->current_period_end->copy()->subDays(7));
        $this->assertSame(1, $this->runRenewals()['memberships_renewed']);
        $this->assertSame(0, $this->runRenewals()['memberships_renewed']);
        $this->assertSame(1, Membership::query()->where('renewed_from_id', $current->id)->count());
    }

    public function test_a_subscription_without_auto_renew_is_left_alone(): void
    {
        $current = $this->renewable(Provider::factory()->create());
        $current->update(['auto_renew' => false]);
        $this->travelTo($current->current_period_end->copy()->subDay());

        $this->assertSame(0, $this->runRenewals()['memberships_renewed']);
    }

    public function test_a_stopped_renewal_retries_until_the_end_and_clears_once_it_succeeds(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->renewable($provider);
        $overdue = Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->travelTo($current->current_period_end->copy()->subDays(5));

        $this->assertSame(1, $this->runRenewals()['memberships_stopped']);
        $this->assertSame(RenewalStopReason::OverdueInvoice, $current->fresh()->renewal_stop_reason);

        $overdue->update(['status' => InvoiceStatus::Paid, 'paid_amount' => $overdue->amount]);
        $this->travel(1)->hours();
        $this->assertSame(1, $this->runRenewals()['memberships_renewed']);
        $this->assertNull($current->fresh()->renewal_stop_reason);
    }

    public function test_no_automatic_attempt_after_the_end(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->renewable($provider);
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->travelTo($current->current_period_end->copy()->subDay());
        $this->runRenewals();

        $this->travelTo($current->current_period_end->copy()->addDay());
        $result = $this->runRenewals();

        $this->assertSame(0, $result['memberships_renewed'] + $result['memberships_stopped']);
        $this->assertSame(RenewalStopReason::OverdueInvoice, $current->fresh()->renewal_stop_reason);
    }

    public function test_the_renewal_gets_its_balances_when_it_starts(): void
    {
        $current = $this->renewable(Provider::factory()->create());
        PlanBalance::create(['plan_id' => $current->plan_id, 'category' => WalletTransactionCategory::General, 'amount_points' => 100]);
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor(OwnerType::Company, (int) $current->owner_id);
        $before = $wallets->generalBalance($wallet);

        $this->travelTo($current->current_period_end->copy()->subDays(3));
        $this->runRenewals();
        $this->assertSame($before, $wallets->generalBalance($wallet));

        $this->travelTo($current->current_period_end->copy()->addMinute());
        $this->assertSame(1, $this->runRenewals()['balances_granted']);
        $this->assertSame(0, $this->runRenewals()['balances_granted']);
        $this->assertSame($before + 100, $wallets->generalBalance($wallet));
    }

    public function test_a_lease_extends_inside_the_window(): void
    {
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false), ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $lease->update(['auto_renew' => true]);
        $this->settleInvoices();

        $this->travelTo($lease->ends_on->subDays(8));
        $this->assertSame(0, $this->runRenewals()['leases_extended']);

        $this->travelTo($lease->ends_on->subDays(7));
        $this->assertSame(1, $this->runRenewals()['leases_extended']);
        $this->assertSame(0, $this->runRenewals()['leases_extended']);
        $this->assertSame(1, $lease->fresh()->renewals_count);
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $this->artisan('renewals:run')->assertSuccessful();

        $this->artisan('schedule:list')->expectsOutputToContain('renewals:run');
    }
}

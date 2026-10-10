<?php

namespace Tests\Feature\Leasing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;

class LeasePeriodInvoiceTest extends LeasingTestCase
{
    private function period(array $leaseAttributes = [], array $periodAttributes = []): OfficeLeasePeriod
    {
        $lease = OfficeLease::factory()->create($leaseAttributes);

        return OfficeLeasePeriod::factory()->create([
            'office_lease_id' => $lease->id,
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-06-30',
            'amount' => '1500.00',
            ...$periodAttributes,
        ]);
    }

    public function test_a_period_invoice_is_the_fixed_amount_without_points(): void
    {
        $period = $this->period();

        $invoice = app(InvoiceIssuer::class)->issueForLeasePeriod($period, null);

        $this->assertNull($invoice->points);
        $this->assertSame('1500.00', (string) $invoice->amount);
        $this->assertSame('1500.00', (string) $invoice->computed_amount);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(0, bccomp((string) $invoice->exchange_rate_to_base, '1', 10));
        $this->assertSame($period->id, $invoice->office_lease_period_id);
        $this->assertNull($invoice->provider_id);
        $this->assertSame(Payer::Company, $invoice->billed_to);
        $this->assertNull($invoice->issued_by);
        $this->assertSame(now()->addDays(15)->toDateString(), $invoice->due_at->toDateString());
        $this->assertStringStartsWith('Office lease '.$period->lease->reference.':', $invoice->description);
        $this->assertStringContainsString('2026-04-01 – 2026-06-30', $invoice->description);
        $this->assertSame(LeasePeriodStatus::Invoiced, $period->fresh()->status);
    }

    public function test_a_provider_lease_bills_the_chosen_payer_on_the_provider_terms(): void
    {
        $provider = Provider::factory()->create(['payment_terms_days' => 30]);
        $period = $this->period(['provider_id' => $provider->id, 'billed_to' => Payer::Provider]);

        $invoice = app(InvoiceIssuer::class)->issueForLeasePeriod($period, null);

        $this->assertSame($provider->id, $invoice->provider_id);
        $this->assertSame(Payer::Provider, $invoice->billed_to);
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_at->toDateString());
    }

    public function test_a_currency_without_a_rate_is_refused(): void
    {
        $period = $this->period(['currency' => 'SYP', 'monthly_amount' => '5000000.00'], ['amount' => '15000000.00']);

        $this->expectException(InvoicingException::class);

        try {
            app(InvoiceIssuer::class)->issueForLeasePeriod($period, null);
        } finally {
            $this->assertSame(LeasePeriodStatus::Upcoming, $period->fresh()->status);
        }
    }

    public function test_a_period_invoice_takes_partial_payments_and_cancels_like_any_invoice(): void
    {
        $invoice = app(InvoiceIssuer::class)->issueForLeasePeriod($this->period(), null);
        $admin = $this->actingAsAdmin();
        $payments = app(InvoicePaymentService::class);

        $payment = $payments->record($invoice, [
            'amount' => '500.00', 'currency' => 'USD', 'method' => 'bank_transfer',
            'receipt_number' => 'TRF-1', 'paid_at' => now()->toDateString(),
        ], $admin);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $payments->void($payment, 'Wrong invoice', $admin);
        $payments->cancel($invoice->fresh(), 'Issued in error', $admin);

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(LeasePeriodStatus::Invoiced, $invoice->leasePeriod->status);
    }
}

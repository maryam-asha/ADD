<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Services\InvoicePaymentService;

class InvoicePaymentServiceTest extends InvoicingTestCase
{
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
    }

    private function service(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function pay(Invoice $invoice, string $amount, string $currency = 'USD'): InvoicePayment
    {
        return $this->service()->record($invoice, [
            'amount' => $amount, 'currency' => $currency, 'method' => 'bank_transfer',
            'receipt_number' => 'R-1', 'paid_at' => now()->toDateString(),
        ], $this->staff);
    }

    public function test_partial_then_final_payment(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);

        $this->pay($invoice, '40');
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('60.00', $invoice->fresh()->remaining());

        $this->pay($invoice, '60');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_payment_in_another_currency_is_converted_at_todays_rate(): void
    {
        $this->syp('0.0001');
        $invoice = Invoice::factory()->create(['amount' => '100.00']);

        $payment = $this->pay($invoice, '300000', 'SYP');

        $this->assertSame('30.00', (string) $payment->amount);
        $this->assertSame('300000.00', (string) $payment->received_amount);
        $this->assertSame('SYP', $payment->received_currency);
        $this->assertSame('30.00', (string) $invoice->fresh()->paid_amount);
    }

    public function test_an_exact_equivalent_settles_the_invoice_despite_truncation(): void
    {
        $this->syp('0.0000680272');
        $invoice = Invoice::factory()->create(['amount' => '10.00']);

        $this->pay($invoice, '147000', 'SYP'); // 9.9999984 USD, truncated 9.99

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('10.00', (string) $invoice->fresh()->paid_amount);
    }

    public function test_more_than_what_is_left_is_refused(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $this->pay($invoice, '70');

        try {
            $this->pay($invoice, '31');
            $this->fail('An overpayment was accepted.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.overpayment', $e->messageKey);
            $this->assertSame('30.00', $e->params['remaining']);
        }
    }

    public function test_a_paid_or_cancelled_invoice_takes_no_payment(): void
    {
        $paid = Invoice::factory()->create(['amount' => '10.00', 'paid_amount' => '10.00', 'status' => InvoiceStatus::Paid]);
        $cancelled = Invoice::factory()->create(['status' => InvoiceStatus::Cancelled]);

        foreach ([$paid, $cancelled] as $invoice) {
            try {
                $this->pay($invoice, '1');
                $this->fail('A payment was accepted.');
            } catch (InvoicingException $e) {
                $this->assertSame(409, $e->status);
            }
        }
    }

    public function test_voiding_the_only_payment_reopens_the_invoice_and_allows_cancelling(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '50.00']);
        $payment = $this->pay($invoice, '50');

        try {
            $this->service()->cancel($invoice->fresh(), 'Wrong', $this->staff);
            $this->fail('An invoice with a payment was cancelled.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.has_payments', $e->messageKey);
        }

        $this->service()->void($payment, 'Typed the wrong invoice', $this->staff);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->paid_amount);

        $this->service()->cancel($invoice->fresh(), 'Waived', $this->staff);
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame('Waived', $invoice->fresh()->cancellation_reason);
    }

    public function test_a_voided_payment_cannot_be_voided_again(): void
    {
        $payment = $this->pay(Invoice::factory()->create(), '10');
        $this->service()->void($payment, 'x', $this->staff);

        $this->expectException(InvoicingException::class);
        $this->service()->void($payment->fresh(), 'y', $this->staff);
    }

    public function test_a_same_currency_payment_one_unit_short_stays_partially_paid(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);

        $this->pay($invoice, '99.99');

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('0.01', $invoice->fresh()->remaining());
    }

    public function test_a_converted_payment_two_units_short_does_not_settle(): void
    {
        $this->syp('0.0001');
        $invoice = Invoice::factory()->create(['amount' => '10.00']);

        $this->pay($invoice, '99800', 'SYP'); // 9.98 USD

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('9.98', (string) $invoice->fresh()->paid_amount);
    }

    public function test_an_amount_that_converts_to_nothing_is_refused(): void
    {
        $this->syp('0.0001');
        $invoice = Invoice::factory()->create(['amount' => '10.00']);

        foreach ([['1', 'SYP'], ['0', 'USD']] as [$amount, $currency]) {
            try {
                $this->pay($invoice, $amount, $currency);
                $this->fail('A zero-value payment was accepted.');
            } catch (InvoicingException $e) {
                $this->assertSame('api.invoice.amount_too_small', $e->messageKey);
            }
        }

        $this->assertSame(0, InvoicePayment::query()->count());
    }

    public function test_a_whole_unit_currency_invoice_settles_and_voiding_one_of_two_payments_reopens_it(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '1000', 'currency' => 'SYP']);

        $first = $this->pay($invoice, '400', 'SYP');
        $this->pay($invoice, '600', 'SYP');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $this->service()->void($first, 'Mistake', $this->staff);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $this->expectException(InvoicingException::class);
        $this->pay(Invoice::factory()->create(), '1000', 'SYP');
    }
}

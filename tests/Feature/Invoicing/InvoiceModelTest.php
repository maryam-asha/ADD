<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invoice_gets_a_yearly_reference(): void
    {
        $invoice = Invoice::factory()->create(['issued_at' => '2026-10-10']);

        $this->assertMatchesRegularExpression('/^INV-2026-\d{5}$/', $invoice->reference);
    }

    public function test_status_follows_the_active_payments(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00', 'paid_amount' => '0.00']);

        $invoice->paid_amount = '40.00';
        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame('60', $invoice->remaining());

        $invoice->paid_amount = '100.00';
        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        $invoice->paid_amount = '0.00';
        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
    }

    public function test_a_cancelled_invoice_keeps_its_status(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Cancelled, 'paid_amount' => '0.00']);

        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
    }

    public function test_overdue_is_open_and_past_its_due_date(): void
    {
        Carbon::setTestNow('2026-10-20');
        $late = Invoice::factory()->create(['due_at' => '2026-10-19']);
        Invoice::factory()->create(['due_at' => '2026-10-20']);
        Invoice::factory()->create(['due_at' => '2026-10-01', 'status' => InvoiceStatus::Paid, 'paid_amount' => '100.00', 'amount' => '100.00']);
        Invoice::factory()->create(['due_at' => '2026-10-01', 'status' => InvoiceStatus::Cancelled]);

        $this->assertSame([$late->id], Invoice::query()->overdue()->pluck('id')->all());
        $this->assertTrue($late->fresh()->isOverdue());
    }

    public function test_a_voided_payment_is_not_active(): void
    {
        $payment = InvoicePayment::factory()->create(['voided_at' => now()]);

        $this->assertTrue($payment->isVoided());
        $this->assertSame(0, InvoicePayment::query()->active()->count());
    }
}

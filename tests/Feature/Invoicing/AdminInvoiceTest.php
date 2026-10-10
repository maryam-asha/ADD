<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Providers\Models\Provider;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class AdminInvoiceTest extends InvoicingTestCase
{
    public function test_the_list_filters_by_status_overdue_provider_and_payer(): void
    {
        Carbon::setTestNow('2026-10-20');
        $provider = Provider::factory()->create();
        $late = Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $this->clientCompany($provider)->id, 'due_at' => '2026-10-01']);
        Invoice::factory()->create(['due_at' => '2026-11-01', 'billed_to' => 'provider']);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/invoices')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/invoices?overdue=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late->id)
            ->assertJsonPath('data.0.is_overdue', true);
        $this->getJson("/api/v1/admin/invoices?provider_id={$provider->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/invoices?billed_to=provider')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/invoices?status=paid')->assertJsonCount(0, 'data');
    }

    public function test_a_payment_is_recorded_and_the_invoice_shows_it(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $staff = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '25', 'currency' => 'USD', 'method' => 'cash', 'receipt_number' => 'R-77', 'paid_at' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.amount', '25.00');

        $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.remaining', '75.00')
            ->assertJsonPath('data.payments.0.receipt_number', 'R-77')
            ->assertJsonPath('data.payments.0.recorded_by.id', $staff->id);
    }

    public function test_an_overpayment_answers_422_with_the_remainder(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '10.00']);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '11', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('message', __('api.invoice.overpayment', ['remaining' => '10.00', 'currency' => 'USD']));
    }

    public function test_void_and_cancel_need_a_reason(): void
    {
        $payment = InvoicePayment::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoice-payments/{$payment->id}/void")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/invoices/{$payment->invoice_id}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_cancelling_an_unpaid_invoice(): void
    {
        $invoice = Invoice::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/cancel", ['reason' => 'Waived'])
            ->assertOk()->assertJsonPath('message', __('api.invoice.cancelled'));

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_each_action_follows_its_permission(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $role = Role::findOrCreate('accounts', 'web');
        $role->syncPermissions(['invoices.view']);
        $user = User::factory()->create();
        $user->assignRole('accounts');
        Sanctum::actingAs($user, ['*']);

        $body = ['amount' => '5', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString()];

        $this->getJson('/api/v1/admin/invoices')->assertOk();
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", $body)->assertForbidden();

        $role->givePermissionTo('invoices.record_payment');
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", $body)->assertCreated();
    }

    public function test_operations_without_permissions_and_providers_are_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $this->getJson('/api/v1/admin/invoices')->assertForbidden();

        $this->providerAccount();
        $this->getJson('/api/v1/admin/invoices')->assertForbidden();
    }

    public function test_a_payment_amount_must_fit_the_currencys_decimals(): void
    {
        $this->syp('0.0001');
        $invoice = Invoice::factory()->create(['amount' => '200.00']);
        $this->actingAsAdmin();
        $base = ['method' => 'cash', 'paid_at' => now()->toDateString()];

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [...$base, 'amount' => '100.5', 'currency' => 'SYP'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [...$base, 'amount' => '100.50', 'currency' => 'USD'])
            ->assertCreated();
    }

    public function test_voiding_a_payment_reopens_the_invoice_and_names_who_did_it(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'paid_amount' => '50.00', 'status' => InvoiceStatus::Paid]);
        $payment = InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => '50.00']);
        $staff = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoice-payments/{$payment->id}/void", ['reason' => 'Wrong invoice'])
            ->assertOk()->assertJsonPath('message', __('api.invoice.payment_voided'));

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertJsonPath('data.payments.0.voided', true)
            ->assertJsonPath('data.payments.0.voided_by.id', $staff->id);
    }

    public function test_void_and_cancel_have_their_own_permissions(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $payment = InvoicePayment::factory()->create();
        $role = Role::findOrCreate('accounts', 'web');
        $role->syncPermissions(['invoices.view', 'invoices.record_payment']);
        $user = User::factory()->create();
        $user->assignRole('accounts');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/admin/invoice-payments/{$payment->id}/void", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }
}

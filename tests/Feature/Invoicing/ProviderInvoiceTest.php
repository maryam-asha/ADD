<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Providers\Models\Provider;

class ProviderInvoiceTest extends InvoicingTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->providerAccount($this->provider);
    }

    private function mine(array $attributes = []): Invoice
    {
        return Invoice::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->clientCompany($this->provider)->id,
            ...$attributes,
        ]);
    }

    public function test_a_provider_lists_only_its_invoices(): void
    {
        $mine = $this->mine();
        Invoice::factory()->create();

        $this->getJson('/api/v1/provider/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_another_providers_invoice_is_not_found(): void
    {
        $theirs = Invoice::factory()->create();

        $this->getJson("/api/v1/provider/invoices/{$theirs->id}")->assertNotFound();
    }

    public function test_the_provider_view_leaves_out_who_at_add_did_what(): void
    {
        $invoice = $this->mine();
        InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'recorded_by' => $this->actingAsAdmin()->id]);
        $this->providerAccount($this->provider);

        $this->getJson("/api/v1/provider/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.amount', '10.00')
            ->assertJsonMissingPath('data.issued_by')
            ->assertJsonMissingPath('data.computed_amount')
            ->assertJsonMissingPath('data.payments.0.recorded_by')
            ->assertJsonMissingPath('data.exchange_rate_to_base')
            ->assertJsonMissingPath('data.cancelled_by')
            ->assertJsonMissingPath('data.cancellation_reason')
            ->assertJsonMissingPath('data.payments.0.voided_by')
            ->assertJsonMissingPath('data.payments.0.void_reason')
            ->assertJsonMissingPath('data.payments.0.exchange_rate');
    }

    public function test_the_provider_list_leaves_out_staff_fields(): void
    {
        $this->mine();

        $this->getJson('/api/v1/provider/invoices')
            ->assertOk()
            ->assertJsonMissingPath('data.0.issued_by')
            ->assertJsonMissingPath('data.0.computed_amount')
            ->assertJsonMissingPath('data.0.cancellation_reason');
    }

    public function test_a_provider_cannot_record_a_payment(): void
    {
        $invoice = $this->mine();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '5', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ])->assertForbidden();
    }
}

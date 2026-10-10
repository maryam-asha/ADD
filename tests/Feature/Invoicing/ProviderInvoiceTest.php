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
            ->assertJsonMissingPath('data.payments.0.recorded_by');
    }

    public function test_a_provider_cannot_record_a_payment(): void
    {
        $invoice = $this->mine();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '5', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ])->assertForbidden();
    }
}

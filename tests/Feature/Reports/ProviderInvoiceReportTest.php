<?php

namespace Tests\Feature\Reports;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Tests\Feature\Invoicing\InvoicingTestCase;

class ProviderInvoiceReportTest extends InvoicingTestCase
{
    private Provider $provider;

    private $c1;

    private $c2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->providerAccount($this->provider);
        $this->c1 = $this->clientCompany($this->provider);
        $this->c2 = $this->clientCompany($this->provider);

        $this->make($this->c1, '100.00', Payer::Company, 15);
        $this->make($this->c1, '200.00', Payer::Provider, -10, ['paid_amount' => '50.00', 'status' => InvoiceStatus::PartiallyPaid]);
        $this->make($this->c2, '300.00', Payer::Provider, -45);
        $this->make($this->c2, '400.00', Payer::Company, -90, ['issued_at' => now()->subDays(100)->toDateString()]);
        $this->make($this->c2, '500000.00', Payer::Company, 5, ['currency' => 'SYP', 'paid_amount' => '500000.00', 'status' => InvoiceStatus::Paid]);
        $this->make($this->c1, '999.00', Payer::Company, -50, ['status' => InvoiceStatus::Cancelled]);

        Invoice::factory()->create(['amount' => '777.00']);
    }

    private function make($company, string $amount, Payer $payer, int $dueInDays, array $extra = []): Invoice
    {
        return Invoice::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $company->id,
            'billed_to' => $payer,
            'amount' => $amount,
            'computed_amount' => $amount,
            'due_at' => now()->addDays($dueInDays)->toDateString(),
            ...$extra,
        ]);
    }

    public function test_totals_are_per_currency_and_exclude_cancelled(): void
    {
        $data = $this->getJson('/api/v1/provider/reports/invoices')->assertOk()->json('data');

        $usd = collect($data['totals'])->firstWhere('currency', 'USD');
        $this->assertSame('1000', $usd['billed']);
        $this->assertSame('50', $usd['paid']);
        $this->assertSame('950', $usd['remaining']);
        $this->assertSame('850', $usd['overdue']);
        $this->assertSame('450', $usd['by_payer']['provider']['remaining']);

        $syp = collect($data['totals'])->firstWhere('currency', 'SYP');
        $this->assertSame('500000', $syp['billed']);
        $this->assertSame('0', $syp['remaining']);
        $this->assertCount(2, $data['totals']);
    }

    public function test_aging_buckets_use_the_remaining(): void
    {
        $aging = collect($this->getJson('/api/v1/provider/reports/invoices')->json('data.aging'))->firstWhere('currency', 'USD');

        $this->assertSame('150', $aging['0_30']);
        $this->assertSame('300', $aging['31_60']);
        $this->assertSame('400', $aging['60_plus']);
    }

    public function test_by_company_lists_only_this_providers_companies(): void
    {
        $byCompany = collect($this->getJson('/api/v1/provider/reports/invoices')->json('data.by_company'));

        $this->assertCount(2, $byCompany);
        $c1 = $byCompany->firstWhere('company.id', $this->c1->id);
        $this->assertSame('250', collect($c1['totals'])->firstWhere('currency', 'USD')['remaining']);
        $c2 = $byCompany->firstWhere('company.id', $this->c2->id);
        $this->assertSame('700', collect($c2['totals'])->firstWhere('currency', 'USD')['remaining']);
    }

    public function test_the_period_filters_on_issue_date(): void
    {
        $from = now()->subDays(95)->toDateString();
        $usd = collect($this->getJson("/api/v1/provider/reports/invoices?from={$from}")->json('data.totals'))->firstWhere('currency', 'USD');

        $this->assertSame('600', $usd['billed']);
    }

    public function test_the_period_is_validated(): void
    {
        $this->getJson('/api/v1/provider/reports/invoices?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    }
}

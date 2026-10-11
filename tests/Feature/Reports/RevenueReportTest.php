<?php

namespace Tests\Feature\Reports;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Invoicing\InvoicingTestCase;

class RevenueReportTest extends InvoicingTestCase
{
    private Provider $p1;

    private Provider $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p1 = Provider::factory()->create(['name' => 'P One']);
        $this->p2 = Provider::factory()->create(['name' => 'P Two']);
    }

    private function invoice(Provider $p, string $amount, array $extra = []): Invoice
    {
        return Invoice::factory()->create([
            'provider_id' => $p->id,
            'company_id' => $this->clientCompany($p)->id,
            'amount' => $amount,
            'computed_amount' => $amount,
            ...$extra,
        ]);
    }

    private function request(Provider $p, ProviderRequestType $type): ProviderRequest
    {
        return ProviderRequest::factory()->create(['provider_id' => $p->id, 'type' => $type]);
    }

    private function seedInvoices(): void
    {
        $this->invoice($this->p1, '100.00', ['provider_request_id' => $this->request($this->p1, ProviderRequestType::WalletTopUp)->id]);
        $this->invoice($this->p1, '200.00', ['provider_request_id' => $this->request($this->p1, ProviderRequestType::PlanAssignment)->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDays(40)->toDateString()]);
        $this->invoice($this->p1, '1500.00', ['office_lease_period_id' => OfficeLeasePeriod::factory()->create()->id]);

        $company = $this->clientCompany($this->p2);
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $this->invoice($this->p2, '300.00', ['company_id' => $company->id, 'membership_id' => $membership->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDays(5)->toDateString()]);
        $this->invoice($this->p2, '40000.00', ['currency' => 'SYP', 'provider_request_id' => $this->request($this->p2, ProviderRequestType::WalletTopUp)->id]);
        $this->invoice($this->p2, '999.00', ['status' => InvoiceStatus::Cancelled]);
    }

    public function test_revenue_is_split_by_source_and_never_merges_currencies(): void
    {
        $this->seedInvoices();
        $this->actingAsAdmin();

        $data = $this->getJson('/api/v1/admin/reports/revenue')->assertOk()->json('data');
        $src = fn (string $cur, string $s) => collect($data['by_source'])->first(fn ($r) => $r['currency'] === $cur && $r['source'] === $s);

        $this->assertSame('100.00', $src('USD', 'wallet_top_up')['billed']);
        $this->assertSame('200.00', $src('USD', 'plan_assignment')['billed']);
        $this->assertSame('1500.00', $src('USD', 'office_lease')['billed']);
        $this->assertSame('300.00', $src('USD', 'membership_renewal')['billed']);
        $this->assertSame('40000.00', $src('SYP', 'wallet_top_up')['billed']);

        $usd = collect($data['totals'])->firstWhere('currency', 'USD');
        $this->assertSame('2100.00', $usd['billed']);
        $this->assertSame('500.00', $usd['overdue']);
        $this->assertSame('40000.00', collect($data['totals'])->firstWhere('currency', 'SYP')['billed']);

        $aging = collect($data['aging'])->firstWhere('currency', 'USD');
        $this->assertSame('300.00', $aging['0_30']);
        $this->assertSame('200.00', $aging['31_60']);
        $this->assertSame('0.00', $aging['60_plus']);
    }

    public function test_top_overdue_orders_by_amount_and_names_the_payer(): void
    {
        $this->seedInvoices();
        $this->actingAsAdmin();

        $top = $this->getJson('/api/v1/admin/reports/revenue')->json('data.top_overdue');

        $this->assertSame('provider', $top[0]['payer']['type']);
        $this->assertSame($this->p2->id, $top[0]['payer']['id']);
        $this->assertSame('P Two', $top[0]['payer']['name']);
        $this->assertSame('300.00', $top[0]['overdue']);
        $this->assertSame('P One', $top[1]['payer']['name']);
        $this->assertSame('200.00', $top[1]['overdue']);
        $this->assertCount(2, $top);
    }

    public function test_it_filters_by_provider_and_period(): void
    {
        $this->seedInvoices();
        $this->invoice($this->p1, '55.00', ['issued_at' => now()->subDays(60)->toDateString()]);
        $this->actingAsAdmin();

        $byProvider = $this->getJson("/api/v1/admin/reports/revenue?provider_id={$this->p2->id}")->json('data.totals');
        $this->assertSame('300.00', collect($byProvider)->firstWhere('currency', 'USD')['billed']);

        $from = now()->subDays(30)->toDateString();
        $recent = $this->getJson("/api/v1/admin/reports/revenue?from={$from}&provider_id={$this->p1->id}")->json('data.totals');
        $this->assertSame('1800.00', collect($recent)->firstWhere('currency', 'USD')['billed']);
    }

    public function test_operations_without_the_permission_is_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/reports/revenue')->assertForbidden();
    }

    public function test_an_unknown_provider_is_a_validation_error(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/revenue?provider_id=99999')->assertUnprocessable();
    }
}

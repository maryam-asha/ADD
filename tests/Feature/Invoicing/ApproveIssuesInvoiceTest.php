<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApproveIssuesInvoiceTest extends InvoicingTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00');
        $this->provider = Provider::factory()->create(['payment_terms_days' => 30]);
    }

    private function assignment(int $seats = 5, string $payer = 'provider'): ProviderRequest
    {
        $branch = $this->branchWithSeats(50);

        return ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->clientCompany($this->provider, ['branch_id' => $branch->id])->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => Plan::factory()->create(['price_points' => 900])->id, 'seats' => $seats, 'payer' => $payer],
        ]);
    }

    public function test_approving_an_assignment_issues_an_invoice_to_the_payer(): void
    {
        $request = $this->assignment();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $invoice = Invoice::sole();
        $this->assertSame($request->id, $invoice->provider_request_id);
        $this->assertSame(4500, $invoice->points);
        $this->assertSame('450.00', (string) $invoice->amount);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame('provider', $invoice->billed_to->value);
        $this->assertSame('2026-11-09', $invoice->due_at->toDateString());
    }

    public function test_the_reviewer_sets_currency_amount_and_due_date_at_approval(): void
    {
        $this->syp('0.0001');
        $request = $this->assignment(1);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", [
            'invoice_currency' => 'SYP',
            'invoice_amount' => '850000',
            'invoice_due_at' => '2026-12-01',
        ])->assertOk();

        $invoice = Invoice::sole();
        $this->assertSame('SYP', $invoice->currency);
        $this->assertSame('900000.00', (string) $invoice->computed_amount);
        $this->assertSame('850000.00', (string) $invoice->amount);
        $this->assertSame('2026-12-01', $invoice->due_at->toDateString());
    }

    public function test_a_currency_with_no_rate_refuses_the_whole_approval(): void
    {
        $request = $this->assignment();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", ['invoice_currency' => 'SYP'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.invoice.no_exchange_rate', ['currency' => 'SYP']));

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(0, Membership::query()->where('owner_type', OwnerType::Company)->count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_top_up_is_invoiced_and_an_employee_is_not(): void
    {
        $company = $this->clientCompany($this->provider);
        $topUp = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id, 'company_id' => $company->id,
            'payload' => ['points' => 500, 'payer' => 'company'],
        ]);
        $member = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id, 'company_id' => $company->id,
            'type' => ProviderRequestType::CompanyMember,
            'payload' => ['phone' => '+963944444444', 'name' => 'B'],
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$topUp->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/provider-requests/{$member->id}/approve")->assertOk();

        $this->assertSame([$topUp->id], Invoice::query()->pluck('provider_request_id')->all());
        $this->assertSame('company', Invoice::sole()->billed_to->value);
    }

    public function test_the_request_shows_a_preview_before_and_the_invoice_after(): void
    {
        $request = $this->assignment();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/provider-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_preview.amount', '450.00')
            ->assertJsonPath('data.invoice_preview.currency', 'USD')
            ->assertJsonPath('data.invoice_preview.points', 4500)
            ->assertJsonPath('data.invoice_preview.due_at', '2026-11-09')
            ->assertJsonPath('data.invoice', null);

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->getJson("/api/v1/admin/provider-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_preview', null)
            ->assertJsonPath('data.invoice.reference', Invoice::sole()->reference);
    }

    public function test_the_admin_sets_a_providers_payment_terms(): void
    {
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/providers/{$this->provider->id}", ['payment_terms_days' => 45])->assertOk();
        $this->getJson("/api/v1/admin/providers/{$this->provider->id}")->assertJsonPath('data.payment_terms_days', 45);

        $this->patchJson("/api/v1/admin/providers/{$this->provider->id}", ['payment_terms_days' => null])->assertOk();
        $this->assertNull($this->provider->fresh()->payment_terms_days);
    }

    public function test_a_free_plan_activates_without_an_invoice_or_preview(): void
    {
        $branch = $this->branchWithSeats(50);
        $request = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->clientCompany($this->provider, ['branch_id' => $branch->id])->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => Plan::factory()->create(['price_points' => 0])->id, 'seats' => 2, 'payer' => 'provider'],
        ]);
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/provider-requests/{$request->id}")->assertOk()->assertJsonPath('data.invoice_preview', null);
        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, Membership::query()->where('owner_type', OwnerType::Company)->count());
    }

    public function test_a_request_whose_plan_is_gone_still_shows(): void
    {
        $request = $this->assignment();
        DB::table('plans')->delete();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/provider-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_preview', null);
    }

    public function test_the_invoice_amount_must_fit_the_currencys_decimals(): void
    {
        $this->syp('0.0001');
        $request = $this->assignment(1);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", ['invoice_currency' => 'SYP', 'invoice_amount' => '100.5'])
            ->assertStatus(422)->assertJsonValidationErrors('invoice_amount');
        $this->assertTrue($request->fresh()->isPending());

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", ['invoice_amount' => '100.50'])->assertOk();
        $this->assertSame('100.50', (string) Invoice::sole()->amount);
    }
}

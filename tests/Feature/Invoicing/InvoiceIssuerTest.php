<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;

class InvoiceIssuerTest extends InvoicingTestCase
{
    private function issuer(): InvoiceIssuer
    {
        return app(InvoiceIssuer::class);
    }

    private function assignment(Provider $provider, Plan $plan, int $seats, string $payer = 'provider'): ProviderRequest
    {
        return ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'company_id' => $this->clientCompany($provider)->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => $plan->id, 'seats' => $seats, 'payer' => $payer],
        ]);
    }

    public function test_an_assignment_bills_the_plan_price_times_the_seats(): void
    {
        $provider = Provider::factory()->create();
        $plan = Plan::factory()->create(['price_points' => 900, 'duration_days' => 30]);

        $draft = $this->issuer()->draftFor($this->assignment($provider, $plan, 5));

        $this->assertSame(4500, $draft->points);
        $this->assertSame(Payer::Provider, $draft->billedTo);
        $this->assertSame($provider->id, $draft->providerId);
        $this->assertStringContainsString('× 5 seats', $draft->description);
    }

    public function test_a_top_up_bills_its_points_and_other_types_bill_nothing(): void
    {
        $provider = Provider::factory()->create();
        $topUp = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'company_id' => $this->clientCompany($provider)->id,
            'payload' => ['points' => 500, 'payer' => 'company'],
        ]);
        $member = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'type' => ProviderRequestType::CompanyMember,
            'payload' => ['phone' => '+963911111111', 'name' => 'A'],
        ]);

        $this->assertSame(500, $this->issuer()->draftFor($topUp)->points);
        $this->assertSame(Payer::Company, $this->issuer()->draftFor($topUp)->billedTo);
        $this->assertNull($this->issuer()->draftFor($member));
    }

    public function test_the_quote_defaults_to_the_base_currency_and_the_providers_terms(): void
    {
        Carbon::setTestNow('2026-10-10');
        $provider = Provider::factory()->create(['payment_terms_days' => 30]);
        $draft = $this->issuer()->draftFor($this->assignment($provider, Plan::factory()->create(['price_points' => 1000]), 1));

        $quote = $this->issuer()->quote($draft, new InvoiceTerms);

        $this->assertSame('USD', $quote->currency);
        $this->assertSame('100.00', $quote->computedAmount); // point.rate_usd = 0.10
        $this->assertSame('100.00', $quote->amount);
        $this->assertSame('2026-11-09', $quote->dueAt);
    }

    public function test_a_provider_without_terms_falls_back_to_the_default_days(): void
    {
        Carbon::setTestNow('2026-10-10');
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(), 1));

        $this->assertSame('2026-10-25', $this->issuer()->quote($draft, new InvoiceTerms)->dueAt);
    }

    public function test_the_reviewer_may_change_currency_amount_and_due_date(): void
    {
        $this->syp('0.0001');
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(['price_points' => 1000]), 1));

        $quote = $this->issuer()->quote($draft, new InvoiceTerms('SYP', '900000', '2026-12-31'));

        $this->assertSame('SYP', $quote->currency);
        $this->assertSame('1000000', $quote->computedAmount); // 100 USD / 0.0001
        $this->assertSame('900000', $quote->amount);
        $this->assertSame('2026-12-31', $quote->dueAt);
    }

    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(), 1));

        try {
            $this->issuer()->quote($draft, new InvoiceTerms('SYP'));
            $this->fail('A currency with no rate was quoted.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.no_exchange_rate', $e->messageKey);
            $this->assertSame(422, $e->status);
            $this->assertSame('SYP', $e->params['currency']);
        }
    }

    public function test_the_default_due_days_setting_is_used(): void
    {
        Carbon::setTestNow('2026-10-10');
        app(SettingService::class)->set('invoices.default_due_days', 20, SettingValueType::Int);
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(), 1));

        $this->assertSame('2026-10-30', $this->issuer()->quote($draft, new InvoiceTerms)->dueAt);
    }

    public function test_a_zero_point_request_bills_nothing(): void
    {
        $free = Plan::factory()->create(['price_points' => 0]);

        $this->assertNull($this->issuer()->draftFor($this->assignment(Provider::factory()->create(), $free, 3)));
    }

    public function test_the_quote_carries_the_points(): void
    {
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(['price_points' => 900]), 2));

        $this->assertSame(1800, $this->issuer()->quote($draft, new InvoiceTerms)->points);
    }

    public function test_issuing_freezes_the_quote_on_an_invoice(): void
    {
        $provider = Provider::factory()->create();
        $request = $this->assignment($provider, Plan::factory()->create(['price_points' => 900]), 5, 'company');
        $reviewer = $this->actingAsAdmin();

        $invoice = $this->issuer()->issueForProviderRequest($request, $reviewer, new InvoiceTerms(amount: '400.00'));

        $this->assertSame($request->id, $invoice->provider_request_id);
        $this->assertSame($request->company_id, $invoice->company_id);
        $this->assertSame(Payer::Company, $invoice->billed_to);
        $this->assertSame('450.00', (string) $invoice->computed_amount);
        $this->assertSame('400.00', (string) $invoice->amount);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame($reviewer->id, $invoice->issued_by);
    }
}

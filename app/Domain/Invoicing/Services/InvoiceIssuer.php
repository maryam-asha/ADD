<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceDraft;
use App\Domain\Invoicing\Data\InvoiceQuote;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Settings\Services\SettingService;

/**
 * Turns what was granted into a frozen invoice
 * (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md):
 * points priced in the reviewer's currency at today's rate, due after the
 * provider's terms or the default. The draft is source-agnostic; each
 * source gets a thin wrapper (issueForProviderRequest, issueForLeasePeriod, issueForMembershipRenewal).
 */
class InvoiceIssuer
{
    private const DEFAULT_DUE_DAYS = 15;

    public function __construct(
        private readonly ReceivedMoneyConverter $money,
        private readonly CurrencyConversionService $currencies,
        private readonly SettingService $settings,
    ) {}

    /** Null for request types that bill nothing. */
    public function draftFor(ProviderRequest $request): ?InvoiceDraft
    {
        $payload = $request->payload;

        [$points, $description] = match ($request->type) {
            ProviderRequestType::PlanAssignment => $this->assignmentLine($payload),
            ProviderRequestType::WalletTopUp => [(int) $payload['points'], "Wallet top-up: {$payload['points']} points"],
            default => [null, null],
        };

        // Nothing to bill: another request type, or a free plan.
        if ($points === null || $points <= 0) {
            return null;
        }

        return new InvoiceDraft(
            companyId: $request->company_id,
            providerId: $request->provider_id,
            billedTo: Payer::from($payload['payer']),
            points: $points,
            description: $description,
            source: ['provider_request_id' => $request->id],
        );
    }

    public function quote(InvoiceDraft $draft, InvoiceTerms $terms): InvoiceQuote
    {
        $currency = $draft->isFixed()
            ? $draft->fixedCurrency
            : ($terms->currency ?? $this->currencies->baseCurrencyCode());

        if ($draft->isFixed()) {
            $computed = $draft->fixedAmount;
        } else {
            try {
                $computed = $this->money->pointsToAmount($draft->points, $currency);
            } catch (PaymentPricingException) {
                throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $currency]);
            }
        }

        $rate = $currency === $this->currencies->baseCurrencyCode()
            ? '1'
            : $this->currencies->rateToBase($currency);

        // A fixed amount needs no conversion, but the rate is stored with
        // every invoice for reference — without one there is nothing to store.
        if ($rate === null) {
            throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $currency]);
        }

        return new InvoiceQuote(
            points: $draft->points,
            currency: $currency,
            computedAmount: $computed,
            amount: $draft->isFixed() ? $computed : ($terms->amount ?? $computed),
            exchangeRateToBase: (string) $rate,
            issuedAt: now()->toDateString(),
            dueAt: $terms->dueAt ?? now()->addDays($this->dueDays($draft->providerId))->toDateString(),
        );
    }

    public function issue(InvoiceDraft $draft, InvoiceTerms $terms, ?User $reviewer): Invoice
    {
        $quote = $this->quote($draft, $terms);

        $invoice = Invoice::create([
            ...$draft->source,
            'provider_id' => $draft->providerId,
            'company_id' => $draft->companyId,
            'billed_to' => $draft->billedTo,
            'description' => $draft->description,
            'points' => $draft->points,
            'currency' => $quote->currency,
            'computed_amount' => $quote->computedAmount,
            'amount' => $quote->amount,
            'exchange_rate_to_base' => $quote->exchangeRateToBase,
            'paid_amount' => '0',
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => $quote->issuedAt,
            'due_at' => $quote->dueAt,
            'issued_by' => $reviewer?->id,
        ]);

        activity()->causedBy($reviewer)->performedOn($invoice)->withProperties([
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'amount_overridden' => $terms->amount !== null,
            'due_overridden' => $terms->dueAt !== null,
        ])->log('invoice_issued');

        return $invoice;
    }

    public function issueForProviderRequest(ProviderRequest $request, User $reviewer, InvoiceTerms $terms): ?Invoice
    {
        $draft = $this->draftFor($request);

        return $draft === null ? null : $this->issue($draft, $terms, $reviewer);
    }

    /**
     * Bills one lease period at its fixed amount and marks it invoiced. The
     * caller holds the period's row lock inside its transaction
     * (OfficeLeaseService::contract(), LeaseDailyRunner); $issuer is null
     * when the daily run issues it.
     */
    public function issueForLeasePeriod(OfficeLeasePeriod $period, ?User $issuer): Invoice
    {
        $lease = $period->lease()->with('space')->firstOrFail();

        $invoice = $this->issue(new InvoiceDraft(
            companyId: $lease->company_id,
            providerId: $lease->provider_id,
            billedTo: $lease->billed_to,
            points: null,
            description: sprintf(
                'Office lease %s: %s, %s – %s',
                $lease->reference,
                $lease->space->displayTitle('en'),
                $period->starts_on->toDateString(),
                $period->ends_on->toDateString(),
            ),
            source: ['office_lease_period_id' => $period->id],
            fixedAmount: (string) $period->amount,
            fixedCurrency: $lease->currency,
        ), new InvoiceTerms, $issuer);

        $period->update(['status' => LeasePeriodStatus::Invoiced]);

        return $invoice;
    }

    /**
     * Bills a renewal subscription: its plan's price today × its seats, in
     * $currency (the previous invoice's; null = base), to $billedTo, for the
     * company's current provider. Null for a free plan. The caller owns
     * the transaction (MembershipRenewalService::renew()).
     */
    public function issueForMembershipRenewal(Membership $renewal, Payer $billedTo, ?string $currency, ?User $issuer): ?Invoice
    {
        $renewal->loadMissing('plan');
        $points = (int) $renewal->plan->price_points * $renewal->seats;

        if ($points <= 0) {
            return null;
        }

        $company = Company::query()->findOrFail($renewal->owner_id);

        return $this->issue(new InvoiceDraft(
            companyId: $company->id,
            providerId: $company->provider_id,
            billedTo: $billedTo,
            points: $points,
            description: sprintf(
                'Plan renewal: %s × %d seats, %d days',
                $renewal->plan->translate('name', 'en'),
                $renewal->seats,
                (int) $renewal->plan->duration_days,
            ),
            source: ['membership_id' => $renewal->id],
        ), new InvoiceTerms(currency: $currency), $issuer);
    }

    /** @return array{0: int, 1: string} */
    private function assignmentLine(array $payload): array
    {
        $plan = Plan::query()->findOrFail($payload['plan_id']);
        $seats = (int) $payload['seats'];

        return [
            (int) $plan->price_points * $seats,
            sprintf('Plan: %s × %d seats, %d days', $plan->translate('name', 'en'), $seats, (int) $plan->duration_days),
        ];
    }

    private function dueDays(?int $providerId): int
    {
        $terms = $providerId === null ? null : Provider::query()->whereKey($providerId)->value('payment_terms_days');

        return (int) ($terms ?? $this->settings->get('invoices.default_due_days', self::DEFAULT_DUE_DAYS));
    }
}

<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceDraft;
use App\Domain\Invoicing\Data\InvoiceQuote;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
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
 * source gets a thin wrapper (issueForProviderRequest today).
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

        if ($points === null) {
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
        $currency = $terms->currency ?? $this->currencies->baseCurrencyCode();

        try {
            $computed = $this->money->pointsToAmount($draft->points, $currency);
        } catch (PaymentPricingException) {
            throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $currency]);
        }

        $rate = $currency === $this->currencies->baseCurrencyCode()
            ? '1'
            : $this->currencies->rateToBase($currency);

        $issuedAt = now()->toDateString();

        return new InvoiceQuote(
            currency: $currency,
            computedAmount: $computed,
            amount: $terms->amount ?? $computed,
            exchangeRateToBase: (string) $rate,
            issuedAt: $issuedAt,
            dueAt: $terms->dueAt ?? now()->addDays($this->dueDays($draft->providerId))->toDateString(),
        );
    }

    public function issue(InvoiceDraft $draft, InvoiceTerms $terms, User $reviewer): Invoice
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
            'issued_by' => $reviewer->id,
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

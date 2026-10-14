<?php

namespace App\Domain\Reports\Services\Dashboard;

use App\Domain\Finance\Enums\CashRegisterSessionStatus;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Domain\Finance\Models\Payment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Renewals\Services\StoppedRenewals;
use App\Domain\Reports\Services\InvoiceAggregates;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Support\PlainAmount;
use Illuminate\Database\Eloquent\Builder;

/**
 * The finance section of the dashboard's home page: money collected over
 * the period and the one before, where invoices stand, and what is waiting
 * on staff. Money is summed in SQL per currency, never across currencies,
 * and is centre-wide: wallet top-ups and invoices belong to no branch.
 */
final class FinanceOverview
{
    public function __construct(
        private readonly InvoiceAggregates $invoices,
        private readonly StoppedRenewals $stopped,
    ) {}

    /** @return array<string, mixed> */
    public function for(DashboardPeriod $period): array
    {
        $previous = $period->previous();

        return [
            'period' => $period->toArray(),
            'previous_period' => $previous->toArray(),
            'collected' => [
                'current' => $this->collected($period),
                'previous' => $this->collected($previous),
            ],
            'invoices' => [
                'totals' => $this->invoices->totals(Invoice::query()),
                'aging' => $this->invoices->aging(Invoice::query()),
            ],
            'attention' => [
                'pending_provider_requests' => ProviderRequest::query()->where('status', ProviderRequestStatus::Pending)->count(),
                'stopped_renewals' => $this->stopped->count(),
                'pending_exchange_rate_suggestions' => ExchangeRateSuggestion::query()->where('status', ExchangeRateSuggestionStatus::Pending)->count(),
                'open_cash_sessions' => CashRegisterSession::query()->where('status', CashRegisterSessionStatus::Open)->count(),
            ],
        ];
    }

    /**
     * Accepted payments read as the payments screen's summary reads them —
     * cash as handed over at the desk, cards as charged, the points each
     * purpose took — plus invoice payments not voided and what was invoiced.
     *
     * @return array<string, list<array<string, int|string|null>>>
     */
    private function collected(DashboardPeriod $period): array
    {
        $accepted = Payment::query()
            ->where('status', PaymentStatus::Accepted)
            ->where('created_at', '>=', $period->start)
            ->where('created_at', '<', $period->end);

        return [
            'cash' => $this->perCurrency((clone $accepted)->whereNotNull('received_amount'), 'received_currency', 'received_amount'),
            'card' => $this->perCurrency((clone $accepted)->whereNotNull('charged_amount'), 'currency', 'charged_amount'),
            'invoice_payments' => $this->perCurrency(
                InvoicePayment::query()
                    ->whereNull('voided_at')
                    ->whereDate('paid_at', '>=', $period->from)
                    ->whereDate('paid_at', '<=', $period->to),
                'received_currency',
                'received_amount',
            ),
            'invoiced' => array_map(
                fn (array $row) => ['currency' => $row['currency'], 'amount' => $row['billed']],
                $this->invoices->totals($this->invoices->period(Invoice::query(), $period->from, $period->to)),
            ),
            'points_by_purpose' => (clone $accepted)
                ->selectRaw('purpose, SUM(COALESCE(amount_points, points_to_credit, 0)) AS points')
                ->groupBy('purpose')
                ->orderBy('purpose')
                ->toBase()
                ->get()
                ->map(fn (object $row) => ['purpose' => $row->purpose, 'points' => (int) $row->points])
                ->values()
                ->all(),
        ];
    }

    /** @return list<array{currency: string, amount: ?string}> */
    private function perCurrency(Builder $query, string $currency, string $amount): array
    {
        return $query
            ->selectRaw("{$currency} AS currency, SUM({$amount}) AS amount")
            ->groupBy($currency)
            ->orderBy($currency)
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['currency' => $row->currency, 'amount' => PlainAmount::of($row->amount)])
            ->values()
            ->all();
    }
}

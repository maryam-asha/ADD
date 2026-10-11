<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Illuminate\Database\Eloquent\Builder;

/** What ADD billed, collected and is still owed - per currency, by source - and who owes the most overdue. */
final class RevenueReport
{
    private const TOP_OVERDUE = 10;

    public function __construct(private readonly InvoiceAggregates $aggregates) {}

    /** @return array{totals: list<array<string, string>>, by_source: list<array<string, string>>, aging: list<array<string, string>>, top_overdue: list<array<string, mixed>>} */
    public function for(?string $from, ?string $to, ?int $providerId): array
    {
        $scope = fn () => $this->scoped(Invoice::query(), $from, $to, $providerId);

        return [
            'totals' => $this->aggregates->totals($scope()),
            'by_source' => $this->bySource($from, $to, $providerId),
            'aging' => $this->aggregates->aging($scope()),
            'top_overdue' => $this->topOverdue($scope()),
        ];
    }

    private function scoped(Builder $q, ?string $from, ?string $to, ?int $providerId): Builder
    {
        return $this->aggregates->period($q, $from, $to)
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId));
    }

    /** @return list<array<string, string>> */
    private function bySource(?string $from, ?string $to, ?int $providerId): array
    {
        $q = Invoice::query()
            ->leftJoin('provider_requests', 'provider_requests.id', '=', 'invoices.provider_request_id')
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled)
            ->when($from, fn ($q) => $q->whereDate('invoices.issued_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoices.issued_at', '<=', $to))
            ->when($providerId, fn ($q) => $q->where('invoices.provider_id', $providerId));

        return $q->selectRaw('invoices.currency AS currency')
            ->selectRaw("CASE WHEN invoices.office_lease_period_id IS NOT NULL THEN 'office_lease' WHEN invoices.membership_id IS NOT NULL THEN 'membership_renewal' WHEN provider_requests.type = 'wallet_top_up' THEN 'wallet_top_up' ELSE 'plan_assignment' END AS source")
            ->selectRaw('SUM(invoices.amount) AS billed, SUM(invoices.paid_amount) AS paid, SUM(invoices.amount - invoices.paid_amount) AS remaining')
            ->groupBy('currency', 'source')->orderBy('currency')->orderBy('source')
            ->toBase()->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'source' => $r->source,
                'billed' => number_format((float) $r->billed, 2, '.', ''),
                'paid' => number_format((float) $r->paid, 2, '.', ''),
                'remaining' => number_format((float) $r->remaining, 2, '.', ''),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function topOverdue(Builder $invoices): array
    {
        $overdue = $invoices
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid])
            ->whereRaw('DATE(due_at) < ?', [now()->toDateString()]);

        // Ranked within each currency: amounts in different currencies are never compared.
        $rows = (clone $overdue)->distinct()->orderBy('currency')->pluck('currency')
            ->flatMap(fn (string $currency) => (clone $overdue)
                ->where('currency', $currency)
                ->selectRaw('billed_to, currency')
                ->selectRaw('CASE WHEN billed_to = ? THEN provider_id ELSE company_id END AS payer_id', [Payer::Provider->value])
                ->selectRaw('SUM(amount - paid_amount) AS overdue')
                ->groupBy('billed_to', 'payer_id', 'currency')
                ->orderByRaw('SUM(amount - paid_amount) DESC')
                ->limit(self::TOP_OVERDUE)
                ->toBase()->get());

        $providers = Provider::query()->whereIn('id', $rows->where('billed_to', Payer::Provider->value)->pluck('payer_id'))->pluck('name', 'id');
        $companies = Company::query()->whereIn('id', $rows->where('billed_to', Payer::Company->value)->pluck('payer_id'))->pluck('legal_name', 'id');

        return $rows->map(fn ($r) => [
            'payer' => [
                'type' => $r->billed_to,
                'id' => (int) $r->payer_id,
                'name' => $r->billed_to === Payer::Provider->value ? $providers[$r->payer_id] ?? null : $companies[$r->payer_id] ?? null,
            ],
            'currency' => $r->currency,
            'overdue' => number_format((float) $r->overdue, 2, '.', ''),
        ])->values()->all();
    }
}

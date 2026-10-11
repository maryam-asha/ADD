<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;

/** A provider's invoice statement: totals (split by payer), aging and per-company totals. */
final class ProviderInvoiceReport
{
    public function __construct(private readonly InvoiceAggregates $aggregates) {}

    /** @return array{totals: list<array<string, mixed>>, aging: list<array<string, string>>, by_company: list<array<string, mixed>>} */
    public function for(Provider $provider, ?string $from, ?string $to): array
    {
        $scope = fn () => $this->aggregates->period(
            Invoice::query()->where('provider_id', $provider->id), $from, $to
        );

        $byPayer = [];
        foreach (Payer::cases() as $payer) {
            foreach ($this->aggregates->totals($scope()->where('billed_to', $payer)) as $row) {
                $byPayer[$row['currency']][$payer->value] = $row;
            }
        }

        $totals = array_map(function (array $row) use ($byPayer) {
            $row['by_payer'] = [];
            foreach (Payer::cases() as $payer) {
                $row['by_payer'][$payer->value] = $byPayer[$row['currency']][$payer->value]
                    ?? ['currency' => $row['currency'], 'billed' => '0.00', 'paid' => '0.00', 'remaining' => '0.00', 'overdue' => '0.00'];
            }

            return $row;
        }, $this->aggregates->totals($scope()));

        $companyIds = $scope()->where('status', '!=', 'cancelled')->distinct()->pluck('company_id');
        $byCompany = Company::query()->whereIn('id', $companyIds)->orderBy('legal_name')->get()
            ->map(fn (Company $c) => [
                'company' => ['id' => $c->id, 'legal_name' => $c->legal_name],
                'totals' => $this->aggregates->totals($scope()->where('company_id', $c->id)),
            ])->values()->all();

        return [
            'totals' => $totals,
            'aging' => $this->aggregates->aging($scope()),
            'by_company' => $byCompany,
        ];
    }
}

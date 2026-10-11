<?php

namespace App\Domain\Reports\Services;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Support\PlainAmount;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place invoice money is summed for reports
 * (docs/decisions/provider-reports.md). Every figure is a SQL SUM grouped
 * by currency — currencies are never merged — and cancelled invoices are
 * excluded everywhere. Callers pass an already-scoped invoice query.
 */
final class InvoiceAggregates
{
    /** Optional inclusive window on `issued_at`. */
    public function period(Builder $q, ?string $from, ?string $to): Builder
    {
        return $q->when($from, fn ($q) => $q->whereDate('issued_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issued_at', '<=', $to));
    }

    /** @return list<array<string, string>> */
    public function totals(Builder $invoices): array
    {
        $today = now()->toDateString();

        return (clone $invoices)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->selectRaw('currency')
            ->selectRaw('SUM(amount) AS billed, SUM(paid_amount) AS paid, SUM(amount - paid_amount) AS remaining')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND DATE(due_at) < ? THEN amount - paid_amount ELSE 0 END) AS overdue', [...$this->open(), $today])
            ->groupBy('currency')->orderBy('currency')->toBase()->get()
            ->map(fn ($r) => ['currency' => $r->currency] + $this->money($r, ['billed', 'paid', 'remaining', 'overdue']))
            ->values()->all();
    }

    /**
     * What is overdue, by how long: 1-30, 31-60 and 61+ days past due,
     * on the unpaid remainder.
     *
     * @return list<array<string, string>>
     */
    public function aging(Builder $invoices): array
    {
        $today = now()->toDateString();
        $d30 = now()->subDays(30)->toDateString();
        $d31 = now()->subDays(31)->toDateString();
        $d60 = now()->subDays(60)->toDateString();
        [$a, $b] = $this->open();

        return (clone $invoices)
            ->whereIn('status', [$a, $b])
            ->whereRaw('DATE(due_at) < ?', [$today])
            ->selectRaw('currency')
            ->selectRaw('SUM(CASE WHEN DATE(due_at) >= ? THEN amount - paid_amount ELSE 0 END) AS b1', [$d30])
            ->selectRaw('SUM(CASE WHEN DATE(due_at) <= ? AND DATE(due_at) >= ? THEN amount - paid_amount ELSE 0 END) AS b2', [$d31, $d60])
            ->selectRaw('SUM(CASE WHEN DATE(due_at) < ? THEN amount - paid_amount ELSE 0 END) AS b3', [$d60])
            ->groupBy('currency')->orderBy('currency')->toBase()->get()
            ->map(function ($r) {
                $m = $this->money($r, ['b1', 'b2', 'b3']);

                return ['currency' => $r->currency, '0_30' => $m['b1'], '31_60' => $m['b2'], '60_plus' => $m['b3']];
            })
            ->values()->all();
    }

    /** @return list<string> */
    private function open(): array
    {
        return [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value];
    }

    /**
     * Each value is one DB aggregate, formatted — never PHP arithmetic.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function money(object $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = PlainAmount::of($row->$k);
        }

        return $out;
    }
}

<?php

namespace Add\Inventory\Assets\Services;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DepreciationMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Straight-line depreciation by whole months from the day the asset was
 * put in service (else its purchase date): the
 * cost less its salvage value, spread evenly over its useful life. Never
 * stored — computed from the asset as of a day (today, or the day it was
 * disposed of, whichever is first), so a correction to the cost or the life
 * is reflected everywhere at once.
 */
class DepreciationCalculator
{
    /**
     * Null when the asset lacks a cost, a start date or a useful life.
     *
     * @return array{monthly: string, months_elapsed: int, accumulated: string, book_value: string, fully_depreciated: bool}|null
     */
    public function for(Asset $asset, ?CarbonInterface $asOf = null): ?array
    {
        $start = $asset->in_service_on ?? $asset->purchased_on;

        if ($asset->status === AssetStatus::Draft) {
            return null;
        }

        if ($asset->purchase_cost === null || $start === null || ! $asset->useful_life_months) {
            return null;
        }

        $asOf = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        if ($asset->disposed_on !== null && $asset->disposed_on->lt($asOf)) {
            $asOf = $asset->disposed_on;
        }

        $months = $asOf->lt($start) ? 0 : (int) $start->diffInMonths($asOf);
        $months = min($months, $asset->useful_life_months);

        // An intangible asset is amortized: straight line, nothing left.
        $intangible = (bool) $asset->item?->isIntangible();
        $salvage = $intangible ? '0' : ($asset->salvage_value ?? '0');

        [$accumulated, $monthly] = ! $intangible && $asset->depreciation_method === DepreciationMethod::DecliningBalance
            ? $this->decliningBalance($asset->purchase_cost, $salvage, $asset->useful_life_months, $months)
            : $this->straightLine($asset->purchase_cost, $salvage, $asset->useful_life_months, $months);

        [$addedAccumulated, $addedMonthly, $added] = $this->additions($asset, $start, $asOf, $asset->useful_life_months);
        $accumulated = Decimal::round(Decimal::add($accumulated, $addedAccumulated), 2);

        return [
            'monthly' => Decimal::round(Decimal::add($monthly, $addedMonthly), 2),
            'months_elapsed' => $months,
            'accumulated' => $accumulated,
            'book_value' => Decimal::round(Decimal::sub(Decimal::add($asset->purchase_cost, $added), $accumulated), 2),
            'fully_depreciated' => $months >= $asset->useful_life_months,
        ];
    }

    /**
     * Capital additions, each on a straight line over the months of the
     * asset's life left on the day it was added; one added after the life
     * is over raises the cost and is not depreciated.
     *
     * @return array{0: string, 1: string, 2: string} accumulated, this month's charge, and their total
     */
    private function additions(Asset $asset, CarbonInterface $start, CarbonInterface $asOf, int $life): array
    {
        $accumulated = '0';
        $monthly = '0';
        $total = '0';

        foreach ($asset->exists ? $asset->additions : [] as $addition) {
            $total = Decimal::add($total, $addition->amount);
            $offset = $addition->added_on->lt($start) ? 0 : (int) $start->diffInMonths($addition->added_on);
            $remaining = $life - $offset;

            if ($remaining <= 0) {
                continue;
            }

            $months = $asOf->lt($addition->added_on) ? 0 : min((int) $addition->added_on->diffInMonths($asOf), $remaining);
            $accumulated = Decimal::add($accumulated, Decimal::div(Decimal::mul($addition->amount, $months), $remaining));

            if ($months < $remaining) {
                $monthly = Decimal::add($monthly, Decimal::div($addition->amount, $remaining));
            }
        }

        return [$accumulated, $monthly, $total];
    }

    /**
     * Cost less salvage spread evenly over the life.
     *
     * @return array{0: string, 1: string} accumulated after `$months`, and a month's charge
     */
    private function straightLine(string $cost, string $salvage, int $life, int $months): array
    {
        $depreciable = Decimal::sub($cost, $salvage);

        if (Decimal::cmp($depreciable, '0') < 0) {
            $depreciable = '0';
        }

        return [
            Decimal::round(Decimal::div(Decimal::mul($depreciable, $months), $life), 2),
            Decimal::round(Decimal::div($depreciable, $life), 2),
        ];
    }

    /**
     * Twice the straight-line rate (2 / life) on the book value left each
     * month, never below salvage, and at salvage once the life is over.
     *
     * @return array{0: string, 1: string} accumulated after `$months`, and the latest month's charge
     */
    private function decliningBalance(string $cost, string $salvage, int $life, int $months): array
    {
        $floor = Decimal::cmp($salvage, $cost) > 0 ? $cost : $salvage;
        $keep = Decimal::sub('1', Decimal::div('2', $life));
        $book = $cost;
        $previous = $cost;

        for ($month = 1; $month <= $months; $month++) {
            $previous = $book;
            $book = $month >= $life ? $floor : Decimal::mul($book, $keep);

            if (Decimal::cmp($book, $floor) < 0) {
                $book = $floor;
            }
        }

        // Before the first month: what the first month will take.
        $charge = $months === 0
            ? Decimal::min(Decimal::sub($cost, Decimal::mul($cost, $keep)), Decimal::sub($cost, $floor))
            : Decimal::sub($previous, $book);

        return [Decimal::round(Decimal::sub($cost, $book), 2), Decimal::round($charge, 2)];
    }
}

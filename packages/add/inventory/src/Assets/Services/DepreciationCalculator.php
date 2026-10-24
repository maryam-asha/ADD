<?php

namespace Add\Inventory\Assets\Services;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Straight-line depreciation by whole months from the purchase date: the
 * cost less its salvage value, spread evenly over its useful life. Never
 * stored — computed from the asset as of a day (today, or the day it was
 * disposed of, whichever is first), so a correction to the cost or the life
 * is reflected everywhere at once.
 */
class DepreciationCalculator
{
    /**
     * Null when the asset lacks a cost, a purchase date or a useful life.
     *
     * @return array{monthly: string, months_elapsed: int, accumulated: string, book_value: string, fully_depreciated: bool}|null
     */
    public function for(Asset $asset, ?CarbonInterface $asOf = null): ?array
    {
        if ($asset->purchase_cost === null || $asset->purchased_on === null || ! $asset->useful_life_months) {
            return null;
        }

        $asOf = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        if ($asset->disposed_on !== null && $asset->disposed_on->lt($asOf)) {
            $asOf = $asset->disposed_on;
        }

        $months = $asOf->lt($asset->purchased_on) ? 0 : (int) $asset->purchased_on->diffInMonths($asOf);
        $months = min($months, $asset->useful_life_months);

        $depreciable = Decimal::sub($asset->purchase_cost, $asset->salvage_value ?? '0');

        if (Decimal::cmp($depreciable, '0') < 0) {
            $depreciable = '0';
        }

        $accumulated = Decimal::round(Decimal::div(Decimal::mul($depreciable, $months), $asset->useful_life_months), 2);

        return [
            'monthly' => Decimal::round(Decimal::div($depreciable, $asset->useful_life_months), 2),
            'months_elapsed' => $months,
            'accumulated' => $accumulated,
            'book_value' => Decimal::sub($asset->purchase_cost, $accumulated),
            'fully_depreciated' => $months >= $asset->useful_life_months,
        ];
    }
}

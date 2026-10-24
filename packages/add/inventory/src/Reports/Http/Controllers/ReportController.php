<?php

namespace Add\Inventory\Reports\Http\Controllers;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Inventory;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\Place;
use Add\Inventory\Support\Presenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Money figures, summed exactly (bcmath) and never across currencies.
 */
class ReportController extends Controller
{
    /**
     * What the stock on hand is worth at its average cost, per location.
     * Optional `location` (+ `within`) narrows it.
     */
    public function stockValuation(Request $request, Presenter $presenter): JsonResponse
    {
        $place = $this->place($request);

        $rows = StockBalance::query()
            ->where('quantity', '>', 0)
            ->when($place, fn (Builder $query, Place $place) => $query->at(
                $request->boolean('within', true) ? Inventory::locations()->within($place) : [$place],
            ))
            ->get()
            ->groupBy(fn (StockBalance $balance) => $balance->location()->key())
            ->map(fn ($balances) => [
                'location' => $presenter->location($balances->first()->location()),
                'items' => $balances->count(),
                'value' => Decimal::round(Decimal::add('0', ...$balances->map(fn (StockBalance $b) => Decimal::mul($b->quantity, $b->average_cost))->all()), 2),
            ])
            ->sortByDesc(fn (array $row) => (float) $row['value'])
            ->values();

        return response()->json(['data' => [
            'currency' => config('inventory.stock.currency'),
            'total' => Decimal::round(Decimal::add('0', ...$rows->pluck('value')->all()), 2),
            'locations' => $rows,
        ]]);
    }

    /**
     * Every asset still owned, with what it cost and what it is worth on
     * the books today, totalled per currency. `include_disposed=1` adds the
     * ones disposed of.
     */
    public function assetRegister(Request $request, DepreciationCalculator $depreciation, Presenter $presenter): JsonResponse
    {
        $assets = Asset::query()
            ->with('item')
            ->when(! $request->boolean('include_disposed'), fn (Builder $query) => $query->where('status', '!=', AssetStatus::Disposed))
            ->orderBy('id')
            ->get();

        $totals = [];
        $rows = $assets->map(function (Asset $asset) use ($depreciation, $presenter, &$totals) {
            $figures = $depreciation->for($asset);

            if ($asset->purchase_cost !== null && $asset->currency !== null) {
                $total = $totals[$asset->currency] ?? ['cost' => '0', 'accumulated' => '0', 'book_value' => '0'];
                $totals[$asset->currency] = [
                    'cost' => Decimal::add($total['cost'], $asset->purchase_cost),
                    'accumulated' => Decimal::add($total['accumulated'], $figures['accumulated'] ?? '0'),
                    'book_value' => Decimal::add($total['book_value'], $figures['book_value'] ?? $asset->purchase_cost),
                ];
            }

            return [
                'id' => $asset->id,
                'code' => $asset->code,
                'item' => ['id' => $asset->item->id, 'name' => $asset->item->name],
                'status' => $asset->status->value,
                'location' => $presenter->location($asset->location()),
                'purchased_on' => $asset->purchased_on?->toDateString(),
                'purchase_cost' => $asset->purchase_cost,
                'currency' => $asset->currency,
                'accumulated' => $figures['accumulated'] ?? null,
                'book_value' => $figures['book_value'] ?? null,
            ];
        });

        return response()->json(['data' => [
            'assets' => $rows->values(),
            'totals' => collect($totals)->map(fn (array $total, string $currency) => ['currency' => $currency, ...$total])->values(),
        ]]);
    }

    private function place(Request $request): ?Place
    {
        $value = $request->input('location');

        return is_array($value) && is_string($value['type'] ?? null) && is_numeric($value['id'] ?? null) ? Place::from($value) : null;
    }
}

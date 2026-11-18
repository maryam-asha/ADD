<?php

namespace Add\Inventory\Warehouses\Http\Controllers;

use Add\Inventory\Stock\Models\StockMinimum;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Warehouses\Http\Requests\WarehouseMinimumsRequest;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class WarehouseMinimumController extends Controller
{
    public function index(Warehouse $warehouse): JsonResponse
    {
        return response()->json(['data' => $this->items($warehouse)]);
    }

    /**
     * Sets the minimums of the items listed (others are left alone); a
     * minimum of 0 removes the item's row. A changed minimum starts afresh:
     * the item is judged against the new one when it next moves.
     */
    public function update(WarehouseMinimumsRequest $request, Warehouse $warehouse): JsonResponse
    {
        DB::transaction(function () use ($request, $warehouse) {
            foreach ($request->validated('items') as $line) {
                $minimum = Decimal::round((string) $line['minimum'], 3);
                $row = StockMinimum::query()->where('warehouse_id', $warehouse->id)->where('item_id', $line['item_id'])->first();

                if (Decimal::isZero($minimum)) {
                    $row?->delete();

                    continue;
                }

                if ($row === null) {
                    StockMinimum::query()->create(['warehouse_id' => $warehouse->id, 'item_id' => $line['item_id'], 'minimum' => $minimum]);
                } elseif (Decimal::cmp($row->minimum, $minimum) !== 0) {
                    $row->update(['minimum' => $minimum, 'alerted_at' => null]);
                }
            }
        });

        return response()->json(['data' => $this->items($warehouse)]);
    }

    /**
     * @return list<array{item_id: int, minimum: string, on_hand: string, below: bool}>
     */
    private function items(Warehouse $warehouse): array
    {
        $stock = app(StockService::class);

        return StockMinimum::query()
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('item_id')
            ->with('item')
            ->get()
            ->map(function (StockMinimum $row) use ($stock, $warehouse) {
                $onHand = $stock->onHand($row->item, $warehouse->asPlace());

                return [
                    'item_id' => $row->item_id,
                    'minimum' => Decimal::trim($row->minimum),
                    'on_hand' => $onHand,
                    'below' => Decimal::cmp($onHand, $row->minimum) < 0,
                ];
            })
            ->all();
    }
}

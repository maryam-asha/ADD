<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\ReceptionWarehouse;
use App\Domain\Retail\Services\ItemSaleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreItemSaleRequest;
use App\Http\Resources\Retail\ItemSaleResource;
use Illuminate\Http\JsonResponse;

/**
 * Reception selling stock (a drink, a notebook) to a member — the plan
 * sale's shape for things on the shelf: paid from their points or with
 * money handed over at today's price, and issued from stock in the same
 * transaction. Too few points answers the shape every pay-with-points path
 * gives (`current`, `required`, `shortage`); an empty shelf, 422 with what
 * is left.
 */
class ItemSaleController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreItemSaleRequest $request, ItemSaleService $sales, ReceptionWarehouse $warehouses): JsonResponse
    {
        try {
            $warehouseId = $request->validated('warehouse_id');
            $from = $warehouses->resolve($warehouseId === null ? null : (int) $warehouseId, $request->user(), forSale: true)->asPlace();
            ['sale' => $sale, 'payment' => $payment] = $sales->sell(
                User::query()->findOrFail($request->validated('user_id')),
                $request->validated('lines'),
                $from,
                $request->validated('payment_method'),
                $request->validated('currency'),
                $request->user(),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (PaymentPricingException) {
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        }

        $this->logSensitiveAction('item_sold_at_reception', $sale, [
            'user_id' => $sale->user_id,
            'total_points' => $sale->total_points,
            'payment_id' => $payment->id,
            'payment_method' => $request->validated('payment_method'),
        ]);

        return response()->json([
            'data' => (new ItemSaleResource($sale->load(['user', 'seller', 'lines.item', 'payment'])))->resolve($request),
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Kitchen\Enums\CashReturn;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\CashReturns;
use App\Http\Controllers\Controller;
use App\Http\Resources\Kitchen\KitchenCashReturnResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The cash reception still owes members for orders that were not made, and
 * what it does with each: hand it back, or credit it as points (2026-11-14
 * spec, decision 4). A row is the order beside what was taken for it.
 */
class KitchenCashReturnController extends Controller
{
    private const RELATIONS = ['user', 'lines.item', 'seatDesk', 'space', 'acceptedPayment.wallet'];

    /** GET admin/reception/kitchen-cash-returns — `branch_id` narrows it; cash still owed, oldest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = KitchenOrder::query()
            ->with(self::RELATIONS)
            ->where('cash_return', CashReturn::Owed)
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branch_id', (int) $validated['branch_id']))
            ->oldest()
            ->oldest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return KitchenCashReturnResource::collection($orders);
    }

    /** POST admin/reception/kitchen-cash-returns/{order}/returned — the cash was handed back. */
    public function returned(Request $request, KitchenOrder $order, CashReturns $returns): KitchenCashReturnResource|JsonResponse
    {
        return $this->act(fn () => $returns->returnedInCash($order, $request->user()));
    }

    /** POST admin/reception/kitchen-cash-returns/{order}/credit-points — the member took points instead. */
    public function creditPoints(Request $request, KitchenOrder $order, CashReturns $returns): KitchenCashReturnResource|JsonResponse
    {
        return $this->act(fn () => $returns->creditAsPoints($order, $request->user()));
    }

    /** @param Closure(): KitchenOrder $action */
    private function act(Closure $action): KitchenCashReturnResource|JsonResponse
    {
        try {
            $order = $action();
        } catch (KitchenOrderException|ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return new KitchenCashReturnResource($order->load(self::RELATIONS));
    }
}

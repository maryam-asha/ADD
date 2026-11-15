<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\ReceptionOrderReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectKitchenOrderRequest;
use App\Http\Requests\Admin\Reception\AcceptKitchenOrderRequest;
use App\Http\Resources\Kitchen\AdminKitchenOrderResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reception's queue of kitchen orders sent without a bill, and its decision
 * on each (docs/superpowers/specs/2026-11-14-kitchen-orders-without-a-bill-design.md,
 * decision 5): accept — taking the cash of a cash order — or reject.
 */
class KitchenOrderReviewController extends Controller
{
    private const RELATIONS = ['user', 'lines.item', 'seatDesk', 'space', 'acceptedPayment.wallet'];

    /** GET admin/reception/kitchen-orders — `branch_id` narrows it; the orders awaiting reception only, oldest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = KitchenOrder::query()
            ->with(self::RELATIONS)
            ->where('status', KitchenOrderStatus::AwaitingReception)
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branch_id', (int) $validated['branch_id']))
            ->oldest()
            ->oldest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return AdminKitchenOrderResource::collection($orders);
    }

    /** POST admin/reception/kitchen-orders/{order}/accept `{amount?, currency?}` — to the kitchen board. */
    public function accept(AcceptKitchenOrderRequest $request, KitchenOrder $order, ReceptionOrderReview $review): AdminKitchenOrderResource|JsonResponse
    {
        return $this->act(fn () => $review->accept(
            $order,
            $request->user(),
            $request->validated('amount') === null ? null : (string) $request->validated('amount'),
            $request->validated('currency'),
        ));
    }

    /** POST admin/reception/kitchen-orders/{order}/reject `{reason}` */
    public function reject(RejectKitchenOrderRequest $request, KitchenOrder $order, ReceptionOrderReview $review): AdminKitchenOrderResource|JsonResponse
    {
        return $this->act(fn () => $review->reject($order, $request->user(), $request->validated('reason')));
    }

    /** @param Closure(): KitchenOrder $action */
    private function act(Closure $action): AdminKitchenOrderResource|JsonResponse
    {
        try {
            $order = $action();
        } catch (KitchenOrderException|ReceptionActionException $e) {
            $body = ['message' => __($e->messageKey, $e->params)];

            // The shortage is what the desk asks the member for.
            if ($e->messageKey === 'api.payment.amount_short') {
                $body += $e->params;
            }

            return response()->json($body, $e->status);
        } catch (PaymentPricingException) {
            // A currency with no rate yet: the desk's to fix, not a gateway
            // outage. Nothing was written; the order is still in the queue.
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        }

        return new AdminKitchenOrderResource($order->load(self::RELATIONS));
    }
}

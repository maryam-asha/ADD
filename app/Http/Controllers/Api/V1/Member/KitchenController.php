<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Kitchen\Services\KitchenMenu;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Kitchen\Services\MenuAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreKitchenOrderRequest;
use App\Http\Resources\Kitchen\KitchenMenuResource;
use App\Http\Resources\Kitchen\KitchenOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The member's side of the kitchen (docs/superpowers/specs/2026-11-10-kitchen-orders-design.md):
 * the menu and whether they may order now, sending an order onto their open
 * session or checked-in booking, their orders, and taking back one the
 * kitchen has not accepted.
 */
class KitchenController extends Controller
{
    /**
     * GET member/kitchen/menu?qr=&branch_id= — the branch is the QR's, else
     * the member's first open bill's, else `branch_id` (required then).
     * Anyone may browse; `can_order` and `reason` say whether they may send.
     */
    public function menu(Request $request, MenuAccess $access, KitchenMenu $menu): JsonResponse
    {
        $validated = $request->validate([
            'qr' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $qr = $this->qr($validated['qr'] ?? null);
        $result = $access->for($request->user(), $qr, isset($validated['branch_id']) ? (int) $validated['branch_id'] : null);

        if ($result['branch_id'] === null) {
            throw ValidationException::withMessages(['branch_id' => __('validation.required', ['attribute' => 'branch id'])]);
        }

        return response()->json(['data' => new KitchenMenuResource([
            'access' => $result,
            'qr' => $qr,
            'items' => $menu->for($result['branch_id']),
        ])]);
    }

    /** POST member/kitchen-orders — 201 with the order, pending the kitchen. */
    public function store(StoreKitchenOrderRequest $request, KitchenOrderService $orders): JsonResponse
    {
        try {
            $order = $orders->place(
                $request->user(),
                array_map(fn (array $line) => [
                    'sale_item_id' => (int) $line['sale_item_id'],
                    'quantity' => (int) $line['quantity'],
                ], $request->validated('lines')),
                $this->qr($request->validated('qr')),
                $request->validated('delivery'),
                $request->validated('bill'),
            );
        } catch (KitchenOrderException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return (new KitchenOrderResource($order))->response()->setStatusCode(201);
    }

    /** GET member/kitchen-orders — the open orders, then the last 20 closed, newest first in each. */
    public function index(Request $request): JsonResponse
    {
        $mine = fn () => KitchenOrder::query()
            ->where('user_id', $request->user()->id)
            ->with('lines.item', 'seatDesk', 'space')
            ->latest()
            ->latest('id');

        $open = $mine()->open()->get();
        $closed = $mine()->whereNotIn('status', KitchenOrderStatus::openValues())->limit(20)->get();

        return response()->json(['data' => KitchenOrderResource::collection($open->concat($closed))]);
    }

    /** GET member/kitchen-orders/{order} — the member's own only. */
    public function show(Request $request, KitchenOrder $order): KitchenOrderResource|JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return $this->notFound();
        }

        return new KitchenOrderResource($order);
    }

    /** POST member/kitchen-orders/{order}/cancel — only while the kitchen has not accepted it. */
    public function cancel(Request $request, KitchenOrder $order, KitchenOrderService $orders): KitchenOrderResource|JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return $this->notFound();
        }

        try {
            $orders->cancelByMember($order);
        } catch (KitchenOrderException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return new KitchenOrderResource($order);
    }

    /**
     * The point a scanned text names. Anything else — and a point whose desk
     * or space is gone — counts as no QR.
     */
    private function qr(?string $value): ?QrPoint
    {
        $qr = $value === null ? null : QrPoint::fromScan(trim($value));

        return $qr?->placeModel() === null ? null : $qr;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('api.kitchen.order_not_found')], 404);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Kitchen\Services\KitchenMenu;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Kitchen\Services\MenuAccess;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreKitchenOrderRequest;
use App\Http\Resources\Kitchen\KitchenMenuResource;
use App\Http\Resources\Kitchen\KitchenOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The member's side of the kitchen (docs/superpowers/specs/2026-11-10-kitchen-orders-design.md,
 * amended by 2026-11-14-kitchen-orders-without-a-bill-design.md): the menu
 * and whether they may order now, sending an order — onto their open
 * session or checked-in booking, or paid when sent — their orders, and
 * taking back one the kitchen has not accepted.
 */
class KitchenController extends Controller
{
    use AnswersPayments;

    /**
     * `next` for an order held for a card whose page could not be opened:
     * still unpaid, so the app offers the methods again (resend with
     * `order_id`). Beside PaymentStart's `done`, `redirect`, `pay_at_reception`.
     */
    public const NEXT_PAY_AGAIN = 'pay_again';

    /**
     * GET member/kitchen/menu — the branch is the member's first open
     * bill's, else the request's (`X-Branch-Id` or `branch_id`,
     * ResolveDefaultBranch), else the default branch. No `qr` here: the scan
     * already answered the place, and the QR goes with the order (2026-11-12).
     * Anyone may browse; `can_order` and `reason` say whether they may send.
     *
     * `items` is paginated — `?per_page=` (1..100, default 15) and `?page=`
     * — with its counters in `data.meta`, inside `data` like the rest
     * of the menu. The page is cut after the stock filter, so `total` counts
     * only what the kitchen can serve.
     */
    public function menu(Request $request, MenuAccess $access, KitchenMenu $menu): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $access->for($request->user(), null, isset($validated['branch_id']) ? (int) $validated['branch_id'] : null);

        $items = $result['branch_id'] === null ? [] : $menu->for($result['branch_id']);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        return response()->json(['data' => new KitchenMenuResource([
            'access' => $result,
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil(count($items) / $perPage)),
                'per_page' => $perPage,
                'total' => count($items),
            ],
        ])]);
    }

    /**
     * POST member/kitchen-orders — 201 with the order in `data`, and beside
     * it how paying went: `payment`, `redirect_url`, `next` (`done` |
     * `redirect` | `pay_at_reception` | `pay_again`), and `payment_error`
     * with `next` = `pay_again` when the card page could not be opened (the
     * order stands, held for its card, and may be sent again with another
     * method).
     *
     * With `order_id`, an order of the member's held for its card is sent
     * again to switch how it is paid: nothing is created, 200. The card
     * payment it had open is canceled at the gateway once the new method
     * has taken, so one order never has two card payments open.
     *
     * A wallet that is short, or one the member may not spend from, refuses
     * the whole send — nothing is written — with the answer every wallet
     * payment gives.
     */
    public function store(StoreKitchenOrderRequest $request, KitchenOrderService $orders, PayableCheckout $checkout): JsonResponse
    {
        $member = $request->user();
        $payment = $request->validated('payment');
        $resent = $request->validated('order_id') !== null;
        // The card payments the held order had open, let go once the new method has taken.
        $staleCards = $resent ? $orders->openCardPayments($member, (int) $request->validated('order_id')) : [];

        try {
            $placed = $resent
                ? $orders->repay($member, (int) $request->validated('order_id'), $payment)
                : $orders->place(
                    $member,
                    array_map(fn (array $line) => [
                        'sale_item_id' => (int) $line['sale_item_id'],
                        'quantity' => (int) $line['quantity'],
                    ], $request->validated('lines')),
                    $this->qr($request->validated('qr')),
                    $request->validated('delivery'),
                    $request->validated('bill'),
                    $payment,
                    // Only the header: the default branch that ResolveDefaultBranch
                    // puts on every POST would hide the office's branch.
                    $request->hasHeader('X-Branch-Id') ? (int) $request->header('X-Branch-Id') : null,
                    $request->validated('note'),
                );
        } catch (KitchenOrderException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $member->id) ?? throw $e;
        }

        $paid = $placed->checkout !== null
            ? $this->checkoutFields($placed->checkout)
            : $this->openCard($checkout, $placed->order, $member);

        $orders->cancelOpenCards($staleCards);

        return response()->json(
            ['data' => (new KitchenOrderResource($placed->order->refresh()))->resolve($request)] + $paid,
            $resent ? 200 : 201,
        );
    }

    /**
     * GET member/kitchen-orders — the open orders, then the closed ones,
     * newest first in each. Paginated like the other member listings —
     * `?per_page=` (1..100, default 15) and `?page=` — the orders under
     * `data`, the page counters under `meta`.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $open = KitchenOrderStatus::openValues();

        $paginator = KitchenOrder::query()
            ->where('user_id', $request->user()->id)
            ->with('lines.item', 'seatDesk', 'space')
            // Open first: one ordering across pages, so no order shows twice or not at all.
            ->orderByRaw('case when status in ('.implode(',', array_fill(0, count($open), '?')).') then 0 else 1 end', $open)
            ->latest()
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        return response()->json([
            'data' => KitchenOrderResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
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
     * The card payment of an order held for it, opened once the order is
     * committed (a gateway never runs inside a transaction). The order
     * stands whatever the gateway says: one that could not be opened is
     * answered as `payment_error` beside it, the hold left to its deadline,
     * and `next` = `pay_again` — the order is held and unpaid, so neither
     * `done` nor a `redirect` with nowhere to go.
     *
     * @return array{payment: mixed, redirect_url: string|null, next: string, payment_error?: string}
     */
    private function openCard(PayableCheckout $checkout, KitchenOrder $order, User $member): array
    {
        $card = $this->payBesideNewRecord($checkout, PaymentPurpose::KitchenOrder, $order, $member, PaymeraGateway::KEY);

        return $card + ['next' => match (true) {
            isset($card['payment_error']) => self::NEXT_PAY_AGAIN,
            $card['redirect_url'] !== null => PaymentStart::REDIRECT,
            default => PaymentStart::DONE,
        }];
    }

    /**
     * The point a scanned text names. Anything else — and a point whose desk
     * or space is gone — counts as no QR.
     */
    private function qr(?string $value): ?QrPoint
    {
        return $value === null ? null : QrPoint::fromScan(trim($value));
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('api.kitchen.order_not_found')], 404);
    }
}

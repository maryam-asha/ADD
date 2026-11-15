<?php

namespace App\Domain\Kitchen\Services;

use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Services\InsufficientStockException;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\CheckoutOutcome;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Enums\OrderSource;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A member sending an order to the kitchen, and taking it back while the
 * kitchen has not accepted it; the kitchen accepting or rejecting it, and
 * marking it ready and delivered (spec "The order"). Every change of status
 * locks the order's row and re-reads its status first, so two screens
 * acting on one order at once cannot both succeed. One lock order
 * everywhere: the bill, then the order, then its payment.
 *
 * Sending checks the stock but draws none: stock is drawn when the kitchen
 * accepts (decision 7). Prices are frozen as they are when it is sent. How
 * it is paid is the member's choice among the methods OrderRouting offers
 * (2026-11-14 spec, decisions 2 and 3): on the bill, as before — its total
 * joins the bill once accepted (decision 9); from a wallet, in the same
 * transaction that writes it; by card, held `awaiting_payment` until the
 * card goes through; or in cash, which reception takes when it lets the
 * order through.
 */
class KitchenOrderService
{
    public function __construct(
        private readonly BillLocator $bills,
        private readonly StockService $stock,
        private readonly KitchenOrderNotifier $notifier,
        private readonly OrderRouting $routing,
        private readonly PaymentService $payments,
        private readonly PaymentMethodRegistry $methods,
        private readonly KitchenOrderRefunder $refunder,
    ) {}

    /**
     * Writes the order on its route, paid by the method chosen.
     *
     * - The bill: when the member has one, the order joins it by
     *   BillLocator's rules whatever the method — an order paid now still
     *   appears on its bill's report, already paid. With none it has no bill.
     * - The branch: the bill's; with no bill, OrderRouting's (the QR's, the
     *   one the app names, the office's, the default).
     * - Its first status: `awaiting_payment` for a card, until the card goes
     *   through or the member switches method (repay()), by
     *   `payments.prepayment_window_minutes`; else `pending` on the kitchen
     *   route, `awaiting_reception` on the reception route.
     * - A wallet pays in the transaction that writes the order: short, or a
     *   wallet the member may not spend from, and nothing is written. A card
     *   is opened by the caller once this has committed (PlacedOrder).
     *
     * @param  list<array{sale_item_id: int, quantity: int}>  $lines
     * @param  array{type?: string, space_id?: int|null, note?: string|null}|null  $delivery
     * @param  array{type: string, id: int|string}|null  $bill
     * @param  array{method: string, wallet_owner_type?: string|null, wallet_owner_id?: int|null}  $payment
     * @param  ?int  $branchId  the branch the app names (`X-Branch-Id`), read only when nothing else does
     *
     * @throws KitchenOrderException a method not offered, no kitchen, a place or a bill refused
     */
    public function place(User $member, array $lines, ?QrPoint $qr, ?array $delivery, ?array $bill, array $payment, ?int $branchId = null): PlacedOrder
    {
        // A point whose desk or space is gone is no QR at all.
        $place = $qr?->placeModel();
        $qr = $place === null ? null : $qr;

        $decision = $this->routing->for($member, $qr, $branchId);
        $choice = $this->offered($payment, $decision->methods);

        $billable = $this->bills->resolve($member, $qr, $bill);
        $branchId = $billable !== null ? (int) $billable->space->building->branch_id : $decision->branchId;

        $store = ($branchId === null ? null : KitchenStore::activeForBranch($branchId))
            ?? throw new KitchenOrderException('api.kitchen.no_kitchen', 422);

        $where = $this->deliveryFor($billable, $billable === null ? $decision->lease : null, $branchId, $qr, $place, $delivery ?? []);
        $priced = $this->price($lines, $store);
        $total = array_sum(array_column($priced, 'line_points'));

        return DB::transaction(function () use ($member, $branchId, $store, $billable, $where, $qr, $priced, $total, $decision, $choice, $payment) {
            // The bill first, then the order — the order every closing takes.
            if ($billable !== null) {
                $locked = $billable::query()->whereKey($billable->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->checked_out_at !== null) {
                    throw new KitchenOrderException('api.kitchen.bill_closed', 409);
                }
            }

            $order = new KitchenOrder([
                'user_id' => $member->id,
                'branch_id' => $branchId,
                'warehouse_id' => $store->warehouse_id,
                'billable_type' => $billable?->getMorphClass(),
                'billable_id' => $billable?->getKey(),
                ...$where,
                'source' => $qr === null ? OrderSource::App : OrderSource::Qr,
                'route' => $decision->route,
                'payment_choice' => $choice,
                'total_points' => $total,
            ]);

            $held = $this->needsCard($choice, $total);
            $status = $held ? KitchenOrderStatus::AwaitingPayment : $order->arrivalStatus();

            $order->forceFill([
                'status' => $status,
                'payment_due_at' => $held ? now()->addMinutes((int) config('payments.prepayment_window_minutes', 15)) : null,
                'reached_kitchen_at' => $status === KitchenOrderStatus::Pending ? now() : null,
            ])->save();

            $order->lines()->createMany($priced);

            $checkout = $this->payNow($order, $member, $choice, $payment);
            $this->notifier->arrived($order);

            return new PlacedOrder($order->refresh(), $checkout);
        });
    }

    /**
     * A held order sent again with another method (2026-11-14 spec,
     * decision 3): nothing is created and no sending rule runs; only how it
     * is paid changes. Only the member's own order, still `awaiting_payment`
     * and inside its deadline. Wallet, cash or the bill take it out of the
     * hold at once, on to its route; a card keeps it held, and the caller
     * opens a new card payment (PlacedOrder).
     *
     * @param  array{method: string, wallet_owner_type?: string|null, wallet_owner_id?: int|null}  $payment
     *
     * @throws KitchenOrderException not a held order of theirs (409); a method not offered (422)
     */
    public function repay(User $member, int $orderId, array $payment): PlacedOrder
    {
        $order = KitchenOrder::query()->where('user_id', $member->id)->find($orderId)
            ?? throw new KitchenOrderException('api.kitchen.order_not_awaiting_payment', 409);

        return DB::transaction(function () use ($order, $member, $payment) {
            $bill = $this->lockedBill($order);
            $locked = $this->locked($order, KitchenOrderStatus::AwaitingPayment, 'api.kitchen.order_not_awaiting_payment');

            if ($locked->payment_due_at === null || ! $locked->payment_due_at->isFuture()) {
                throw new KitchenOrderException('api.kitchen.order_not_awaiting_payment', 409);
            }

            $choice = $this->offered($payment, $this->methodsFor($locked, $bill));
            $locked->forceFill(['payment_choice' => $choice])->save();

            // The wallet's fulfiller lets it go once paid; cash and the bill
            // are settled as a choice, so it goes now.
            if (! in_array($choice, [OrderPaymentChoice::Wallet, OrderPaymentChoice::Card], true)) {
                $locked->releaseFromHold();
                $this->notifier->arrived($locked);
            }

            $checkout = $this->payNow($locked, $member, $choice, $payment);

            return new PlacedOrder($locked->refresh(), $checkout);
        });
    }

    /**
     * The card payments still open on the member's held order — read before
     * it is sent again with another method, so that cancelOpenCards() can
     * let them go once the new method has taken (one card payment open on
     * one order at a time). Empty for anything else.
     *
     * @return list<int>
     */
    public function openCardPayments(User $member, int $orderId): array
    {
        $order = KitchenOrder::query()->where('user_id', $member->id)->find($orderId);

        if ($order === null || $order->status !== KitchenOrderStatus::AwaitingPayment) {
            return [];
        }

        return Payment::query()
            ->forPayable($order)
            ->where('status', PaymentStatus::Pending)
            ->whereIn('method', $this->methods->gatewayKeys())
            ->whereNotNull('payment_id')
            ->pluck('id')
            ->all();
    }

    /**
     * Cancels each of these card payments at its gateway (PaymentGateway::cancel)
     * and marks it canceled — outside any transaction, as every gateway call
     * is. Only one still pending is touched, re-read under the lock a status
     * sync takes (payments:payment:{id}), so a payment the bank settled
     * meanwhile is left to its sync. A gateway that cannot be reached leaves
     * it pending for the reconciler; a card accepted later then finds the
     * order already paid or moved on, as a booking's does.
     *
     * @param  list<int>  $paymentIds
     */
    public function cancelOpenCards(array $paymentIds): void
    {
        foreach ($paymentIds as $id) {
            $lock = Cache::lock("payments:payment:{$id}", 30);

            if (! $lock->get()) {
                continue;
            }

            try {
                $payment = Payment::query()->find($id);

                if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                    continue;
                }

                $this->methods->gatewayFor($payment)->cancel($payment);
                $payment->forceFill(['status' => PaymentStatus::Canceled])->save();
            } catch (Throwable $e) {
                Log::warning('kitchen order: an open card payment could not be canceled', [
                    'payment_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Take back an order the kitchen has not accepted (held for its card,
     * waiting for reception, or pending) and get back what it took. Locked,
     * so an accept landing at the same moment either wins or sees it
     * cancelled. A held card order's open gateway payment is cancelled once
     * the transaction has committed.
     *
     * @throws KitchenOrderException the order is past pending (409)
     */
    public function cancelByMember(KitchenOrder $order): void
    {
        $openCards = [];

        DB::transaction(function () use ($order, &$openCards) {
            $locked = $this->locked($order, [
                KitchenOrderStatus::AwaitingPayment,
                KitchenOrderStatus::AwaitingReception,
                KitchenOrderStatus::Pending,
            ], 'api.kitchen.order_not_pending');

            if ($locked->status === KitchenOrderStatus::AwaitingPayment) {
                $openCards = $this->openCardPayments($locked->user, $locked->id);
            }

            $locked->update([
                'status' => KitchenOrderStatus::Cancelled,
                'cancelled_by' => CancelledBy::Member,
                'cancelled_at' => now(),
            ]);

            $this->refunder->refund($locked, __('api.kitchen.refund_description', ['order' => '#'.$locked->id]));
            $this->notifier->cancelledByMember($locked);
        });

        $this->cancelOpenCards($openCards);

        $order->refresh();
    }

    /**
     * The kitchen takes the order on (spec "accept"): in one transaction,
     * lock the bill and then the order, check the order is still pending
     * and the bill still open, and draw every line from the kitchen store it
     * was sent to. A shelf short of any line rolls the whole of it back —
     * nothing drawn, the order still pending, so the kitchen can reject it.
     *
     * @throws KitchenOrderException not pending or the bill closed (409); a line short (422)
     */
    public function accept(KitchenOrder $order, User $staff): KitchenOrder
    {
        DB::transaction(function () use ($order, $staff) {
            // One lock order everywhere: the bill, then the order — the same
            // as a closing bill cancelling its pending orders.
            $bill = $this->lockedBill($order);
            $locked = $this->locked($order, KitchenOrderStatus::Pending, 'api.kitchen.order_not_pending');

            // Only an order on a bill has one to close; one paid at send or
            // let through by reception has none (2026-11-14 spec).
            if ($locked->hasBill() && (! $bill?->checked_in_at || $bill->checked_out_at !== null)) {
                throw new KitchenOrderException('api.kitchen.bill_closed', 409);
            }

            $store = Warehouse::query()->findOrFail($locked->warehouse_id)->asPlace();
            $source = new Place(KitchenOrder::SOURCE, $locked->id);

            foreach ($locked->lines()->with('item.category', 'item.unit')->get() as $line) {
                try {
                    $this->stock->issue($line->item, $store, $line->quantity, IssueReason::Sale, $source);
                } catch (InsufficientStockException) {
                    throw new KitchenOrderException('api.kitchen.out_of_stock', 422, [
                        'item' => (string) $line->item->translate('name'),
                    ]);
                }
            }

            $locked->update([
                'status' => KitchenOrderStatus::Preparing,
                'accepted_by' => $staff->id,
                'accepted_at' => now(),
            ]);

            $this->notifier->accepted($locked);
        });

        return $order->refresh();
    }

    /**
     * A bill closing takes its unaccepted orders with it: every order on it
     * still held for its card, waiting for reception or pending is cancelled
     * by the system, and what it took is given back (KitchenOrderRefunder).
     * Called with the bill's row already locked (lock order: bill, order,
     * payment). Orders the kitchen accepted stay.
     *
     * Each order cancelled is announced to the kitchen board and its member
     * is told, once the surrounding transaction commits. The open card of a
     * held order is cancelled at its gateway at that same point, never inside
     * the transaction.
     *
     * @return Collection<int, KitchenOrder> the orders it cancelled
     */
    public function cancelPendingFor(Booking|WalkinSession $bill): Collection
    {
        $orders = KitchenOrder::query()
            ->where('billable_type', $bill->getMorphClass())
            ->where('billable_id', $bill->getKey())
            ->whereIn('status', [
                KitchenOrderStatus::AwaitingPayment,
                KitchenOrderStatus::AwaitingReception,
                KitchenOrderStatus::Pending,
            ])
            ->lockForUpdate()
            ->get();

        if ($orders->isEmpty()) {
            return new Collection;
        }

        $openCards = [];

        foreach ($orders as $order) {
            if ($order->status === KitchenOrderStatus::AwaitingPayment) {
                $openCards = [...$openCards, ...$this->openCardPayments($order->user, $order->id)];
            }
        }

        KitchenOrder::query()->whereKey($orders->modelKeys())->update([
            'status' => KitchenOrderStatus::Cancelled,
            'cancelled_by' => CancelledBy::System,
            'cancelled_at' => now(),
        ]);

        $cancelled = KitchenOrder::query()->with('user')->whereKey($orders->modelKeys())->get();

        foreach ($cancelled as $order) {
            $this->refunder->refund($order, __('api.kitchen.refund_description', ['order' => '#'.$order->id]));
            $this->notifier->cancelledBySystem($order);
        }

        if ($openCards !== []) {
            DB::afterCommit(fn () => $this->cancelOpenCards($openCards));
        }

        return $cancelled;
    }

    /**
     * The kitchen turns a pending order down; the member sees the reason.
     * No stock was drawn; what the member paid is given back.
     *
     * @throws KitchenOrderException not pending (409)
     */
    public function reject(KitchenOrder $order, User $staff, string $reason): KitchenOrder
    {
        DB::transaction(function () use ($order, $staff, $reason) {
            $locked = $this->locked($order, KitchenOrderStatus::Pending, 'api.kitchen.order_not_pending');
            $locked->update([
                'status' => KitchenOrderStatus::Rejected,
                'rejection_reason' => trim($reason),
                'rejected_by' => $staff->id,
                'rejected_at' => now(),
            ]);

            $this->refunder->refund($locked, __('api.kitchen.refund_description', ['order' => '#'.$locked->id]));
            $this->notifier->rejected($locked);
        });

        return $order->refresh();
    }

    /**
     * @throws KitchenOrderException not preparing (409)
     */
    public function markReady(KitchenOrder $order, User $staff): KitchenOrder
    {
        DB::transaction(function () use ($order) {
            $locked = $this->locked($order, KitchenOrderStatus::Preparing, 'api.kitchen.order_not_preparing');
            $locked->update([
                'status' => KitchenOrderStatus::Ready,
                'ready_at' => now(),
            ]);

            $this->notifier->ready($locked);
        });

        return $order->refresh();
    }

    /**
     * Handed over — from ready, or straight from preparing when the kitchen
     * carries it to the member without calling it ready first.
     *
     * @throws KitchenOrderException neither preparing nor ready (409)
     */
    public function markDelivered(KitchenOrder $order, User $staff): KitchenOrder
    {
        DB::transaction(function () use ($order, $staff) {
            $locked = $this->locked($order, [KitchenOrderStatus::Preparing, KitchenOrderStatus::Ready], 'api.kitchen.order_not_deliverable');
            $locked->update([
                'status' => KitchenOrderStatus::Delivered,
                'delivered_by' => $staff->id,
                'delivered_at' => now(),
            ]);

            $this->notifier->delivered($locked);
        });

        return $order->refresh();
    }

    /**
     * The order's row, locked and read afresh — a copy the caller loaded
     * earlier may be stale — refused with `$key` (409) unless its status is
     * one of `$allowed`.
     *
     * @param  KitchenOrderStatus|list<KitchenOrderStatus>  $allowed
     */
    private function locked(KitchenOrder $order, KitchenOrderStatus|array $allowed, string $key): KitchenOrder
    {
        $locked = KitchenOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, is_array($allowed) ? $allowed : [$allowed], true)) {
            throw new KitchenOrderException($key, 409);
        }

        return $locked;
    }

    /**
     * The order's session or booking, locked and read afresh; null when it is
     * gone, or when the order has no bill — then nothing is queried.
     */
    private function lockedBill(KitchenOrder $order): Booking|WalkinSession|null
    {
        if (! $order->hasBill()) {
            return null;
        }

        $class = Relation::getMorphedModel($order->billable_type) ?? $order->billable_type;

        /** @var Booking|WalkinSession|null */
        return $class::query()->whereKey($order->billable_id)->lockForUpdate()->first();
    }

    /**
     * The method the member chose, refused (422) unless it is one of `$methods`.
     *
     * @param  array{method: string}  $payment
     * @param  list<string>  $methods
     */
    private function offered(array $payment, array $methods): OrderPaymentChoice
    {
        $choice = OrderPaymentChoice::tryFrom((string) ($payment['method'] ?? ''));

        if ($choice === null || ! in_array($choice->value, $methods, true)) {
            throw new KitchenOrderException('api.kitchen.payment_method_not_offered', 422);
        }

        return $choice;
    }

    /**
     * What a held order may switch to, by the route it was sent on: its bill
     * while that is still open, a wallet, a card, and cash on the reception
     * route only (OrderRouting's rule, for an order that already exists).
     *
     * @return list<string>
     */
    private function methodsFor(KitchenOrder $order, Booking|WalkinSession|null $bill): array
    {
        return [
            ...($bill !== null && $bill->checked_out_at === null ? [OrderPaymentChoice::Bill->value] : []),
            OrderPaymentChoice::Wallet->value,
            OrderPaymentChoice::Card->value,
            ...($order->route === OrderRoute::Reception ? [OrderPaymentChoice::Cash->value] : []),
        ];
    }

    /** A card is opened only for something to pay; a free order is never held. */
    private function needsCard(OrderPaymentChoice $choice, int $total): bool
    {
        return $choice === OrderPaymentChoice::Card && $total > 0;
    }

    /**
     * What paying now comes to. A wallet pays here, inside the caller's
     * transaction — any refusal rolls back the order with it. Cash and the
     * bill pay nothing now. Null for a card still to be opened, which the
     * caller does once the order is committed (a gateway never runs inside a
     * transaction, PaymentService::start()).
     *
     * @param  array{wallet_owner_type?: string|null, wallet_owner_id?: int|null}  $payment
     */
    private function payNow(KitchenOrder $order, User $member, OrderPaymentChoice $choice, array $payment): ?CheckoutOutcome
    {
        if ($choice === OrderPaymentChoice::Cash) {
            return new CheckoutOutcome(null, PaymentStart::payAtReception());
        }

        if ($order->total_points <= 0 || $choice === OrderPaymentChoice::Bill) {
            return new CheckoutOutcome(null, PaymentStart::done());
        }

        if ($choice === OrderPaymentChoice::Card) {
            return null;
        }

        ['payment' => $paid, 'start' => $start] = $this->payments->start(PaymentPurpose::KitchenOrder, $member, WalletMethod::KEY, array_filter([
            'payable' => $order,
            'wallet_owner_type' => $payment['wallet_owner_type'] ?? null,
            'wallet_owner_id' => $payment['wallet_owner_id'] ?? null,
        ], fn ($value) => $value !== null));

        return new CheckoutOutcome($paid, $start);
    }

    /**
     * Where the kitchen takes it (spec decision 2): the scanned desk or
     * space; else a room, office or hall booking's own space unless the
     * member picks the counter, a note or another space of the branch; a
     * coworking member without a QR must pick the counter or a note. With no
     * bill (2026-11-14 spec, "Where it goes"): office staff default to their
     * office, as a booking does to its space; anyone else picks the counter
     * or a note.
     *
     * @param  array{type?: string, space_id?: int|null, note?: string|null}  $delivery
     * @return array{delivery_type: DeliveryType, seat_desk_id: ?int, space_id: ?int, delivery_note: ?string}
     */
    private function deliveryFor(Booking|WalkinSession|null $bill, ?OfficeLease $lease, int $branchId, ?QrPoint $qr, SeatDesk|Space|null $place, array $delivery): array
    {
        $note = trim((string) ($delivery['note'] ?? ''));
        $note = $note === '' ? null : $note;
        $to = fn (DeliveryType $type, ?int $desk = null, ?int $space = null) => [
            'delivery_type' => $type,
            'seat_desk_id' => $desk,
            'space_id' => $space,
            'delivery_note' => $note,
        ];

        if ($qr !== null) {
            if ((int) $qr->branch_id !== $branchId) {
                throw new KitchenOrderException('api.kitchen.space_not_in_branch', 422);
            }

            return $place instanceof SeatDesk
                ? $to(DeliveryType::Desk, desk: $place->id)
                : $to(DeliveryType::Space, space: $place->id);
        }

        $type = $delivery['type'] ?? null;

        if ($type === DeliveryType::Pickup->value) {
            return $to(DeliveryType::Pickup);
        }

        if ($type === DeliveryType::Note->value) {
            return $note === null
                ? throw new KitchenOrderException('api.kitchen.delivery_required', 422)
                : $to(DeliveryType::Note);
        }

        // A room, office or hall booking's space; with no bill, the office's.
        $ownSpace = match (true) {
            $bill instanceof Booking && ! $bill->isCoworking() => (int) $bill->space_id,
            $bill === null && $lease !== null => (int) $lease->space_id,
            default => null,
        };

        if ($ownSpace !== null) {
            $spaceId = $type === DeliveryType::Space->value && isset($delivery['space_id'])
                ? (int) $delivery['space_id']
                : $ownSpace;

            $inBranch = Space::query()
                ->whereKey($spaceId)
                ->whereHas('building', fn ($building) => $building->where('branch_id', $branchId))
                ->exists();

            return $inBranch
                ? $to(DeliveryType::Space, space: $spaceId)
                : throw new KitchenOrderException('api.kitchen.space_not_in_branch', 422);
        }

        throw new KitchenOrderException('api.kitchen.delivery_required', 422);
    }

    /**
     * Each line at today's price, refused when its item is off the menu or
     * the kitchen store holds less than asked (the same item on two lines
     * counts once, in total).
     *
     * @param  list<array{sale_item_id: int, quantity: int}>  $lines
     * @return list<array{sale_item_id: int, inventory_item_id: int, quantity: int, unit_price_points: int, line_points: int}>
     */
    private function price(array $lines, KitchenStore $store): array
    {
        $saleItems = SaleItem::query()->with('item')->whereKey(array_column($lines, 'sale_item_id'))->get()->keyBy('id');
        $place = $store->place();
        $wanted = [];

        foreach ($lines as $line) {
            $wanted[$line['sale_item_id']] = ($wanted[$line['sale_item_id']] ?? 0) + (int) $line['quantity'];
        }

        foreach ($wanted as $saleItemId => $quantity) {
            $saleItem = $saleItems->get($saleItemId);

            if ($saleItem === null
                || ! $saleItem->is_active
                || bccomp((string) $quantity, $this->stock->onHand($saleItem->item, $place), 4) > 0) {
                throw new KitchenOrderException('api.kitchen.out_of_stock', 422, [
                    'item' => (string) ($saleItem?->item->translate('name') ?? $saleItemId),
                ]);
            }
        }

        return array_map(function (array $line) use ($saleItems) {
            $saleItem = $saleItems->get($line['sale_item_id']);
            $quantity = (int) $line['quantity'];

            return [
                'sale_item_id' => $saleItem->id,
                'inventory_item_id' => $saleItem->inventory_item_id,
                'quantity' => $quantity,
                'unit_price_points' => $saleItem->price_points,
                'line_points' => $saleItem->price_points * $quantity,
            ];
        }, array_values($lines));
    }
}

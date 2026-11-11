<?php

namespace App\Domain\Kitchen\Services;

use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Services\InsufficientStockException;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderSource;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * A member sending an order to the kitchen, and taking it back while the
 * kitchen has not accepted it; the kitchen accepting or rejecting it, and
 * marking it ready and delivered (spec "The order"). Every change of status
 * locks the order's row and re-reads its status first, so two screens
 * acting on one order at once cannot both succeed.
 *
 * Sending checks the stock but draws none: stock is drawn when the kitchen
 * accepts (decision 7), and the order is not paid now — its total joins the
 * bill it was sent from once accepted (decision 9). Prices are frozen as
 * they are when it is sent.
 */
class KitchenOrderService
{
    public function __construct(
        private readonly BillLocator $bills,
        private readonly StockService $stock,
        private readonly KitchenOrderNotifier $notifier,
    ) {}

    /**
     * @param  list<array{sale_item_id: int, quantity: int}>  $lines
     * @param  array{type?: string, space_id?: int|null, note?: string|null}|null  $delivery
     * @param  array{type: string, id: int|string}|null  $bill
     *
     * @throws KitchenOrderException
     */
    public function place(User $member, array $lines, ?QrPoint $qr, ?array $delivery, ?array $bill): KitchenOrder
    {
        // A point whose desk or space is gone is no QR at all.
        $place = $qr?->placeModel();
        $qr = $place === null ? null : $qr;

        $billable = $this->bills->resolve($member, $qr, $bill);
        $branchId = (int) $billable->space->building->branch_id;

        $store = KitchenStore::activeForBranch($branchId)
            ?? throw new KitchenOrderException('api.kitchen.no_kitchen', 422);

        $where = $this->deliveryFor($billable, $branchId, $qr, $place, $delivery ?? []);
        $priced = $this->price($lines, $store);

        return DB::transaction(function () use ($member, $branchId, $store, $billable, $where, $qr, $priced) {
            // The bill first, then the order — the order every closing takes.
            $locked = $billable::query()->whereKey($billable->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->checked_out_at !== null) {
                throw new KitchenOrderException('api.kitchen.bill_closed', 409);
            }

            $order = KitchenOrder::query()->create([
                'user_id' => $member->id,
                'branch_id' => $branchId,
                'warehouse_id' => $store->warehouse_id,
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                ...$where,
                'source' => $qr === null ? OrderSource::App : OrderSource::Qr,
                'status' => KitchenOrderStatus::Pending,
                'total_points' => array_sum(array_column($priced, 'line_points')),
            ]);

            $order->lines()->createMany($priced);
            $this->notifier->placed($order);

            return $order;
        });
    }

    /**
     * Take back an order the kitchen has not accepted. Locked, so an accept
     * landing at the same moment either wins or sees it cancelled.
     *
     * @throws KitchenOrderException the order is past pending (409)
     */
    public function cancelByMember(KitchenOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = KitchenOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== KitchenOrderStatus::Pending) {
                throw new KitchenOrderException('api.kitchen.order_not_pending', 409);
            }

            $locked->update([
                'status' => KitchenOrderStatus::Cancelled,
                'cancelled_by' => CancelledBy::Member,
                'cancelled_at' => now(),
            ]);

            $this->notifier->cancelledByMember($locked);
        });

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

            if (! $bill?->checked_in_at || $bill->checked_out_at !== null) {
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
     * A bill closing takes its unaccepted orders with it: every pending order
     * on it is cancelled by the system. Called with the bill's row already
     * locked (lock order: bill, then order). Orders the kitchen accepted stay.
     *
     * Each order cancelled is announced to the kitchen board and its member
     * is told, once the surrounding transaction commits.
     *
     * @return Collection<int, KitchenOrder> the orders it cancelled
     */
    public function cancelPendingFor(Booking|WalkinSession $bill): Collection
    {
        $ids = KitchenOrder::query()
            ->where('billable_type', $bill->getMorphClass())
            ->where('billable_id', $bill->getKey())
            ->where('status', KitchenOrderStatus::Pending)
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return new Collection;
        }

        KitchenOrder::query()->whereKey($ids)->update([
            'status' => KitchenOrderStatus::Cancelled,
            'cancelled_by' => CancelledBy::System,
            'cancelled_at' => now(),
        ]);

        $cancelled = KitchenOrder::query()->with('user')->whereKey($ids)->get();
        $cancelled->each(fn (KitchenOrder $order) => $this->notifier->cancelledBySystem($order));

        return $cancelled;
    }

    /**
     * The kitchen turns a pending order down; the member sees the reason.
     * Nothing was drawn, so nothing is returned.
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

    /** The order's session or booking, locked and read afresh; null when it is gone. */
    private function lockedBill(KitchenOrder $order): Booking|WalkinSession|null
    {
        $class = Relation::getMorphedModel($order->billable_type) ?? $order->billable_type;

        /** @var Booking|WalkinSession|null */
        return $class::query()->whereKey($order->billable_id)->lockForUpdate()->first();
    }

    /**
     * Where the kitchen takes it (spec decision 2): the scanned desk or
     * space; else a room, office or hall booking's own space unless the
     * member picks the counter, a note or another space of the branch; a
     * coworking member without a QR must pick the counter or a note.
     *
     * @param  array{type?: string, space_id?: int|null, note?: string|null}  $delivery
     * @return array{delivery_type: DeliveryType, seat_desk_id: ?int, space_id: ?int, delivery_note: ?string}
     */
    private function deliveryFor(Booking|WalkinSession $bill, int $branchId, ?QrPoint $qr, SeatDesk|Space|null $place, array $delivery): array
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

        if ($bill instanceof Booking && ! $bill->isCoworking()) {
            $spaceId = $type === DeliveryType::Space->value && isset($delivery['space_id'])
                ? (int) $delivery['space_id']
                : $bill->space_id;

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

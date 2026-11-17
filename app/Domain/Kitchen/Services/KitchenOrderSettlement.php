<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\FulfillerRegistry;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Membership\Enums\OwnerType;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Settling a bill's kitchen orders together with the bill. Each order stays
 * its own payable (kitchen orders spec, decision 11) — one payment per
 * order, from general points when paid from the wallet — so the session's
 * or booking's own price and payments are never touched by an order.
 */
class KitchenOrderSettlement
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly FulfillerRegistry $fulfillers,
    ) {}

    /**
     * The bill's accepted orders, paid or not, oldest first — the report's
     * `order` lines. An order that costs nothing is owed nothing and is not
     * among them.
     *
     * @return Collection<int, KitchenOrder>
     */
    public function owedOn(Booking|WalkinSession $bill): Collection
    {
        return $this->owed($bill)->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * The bill's accepted orders not yet paid, oldest first.
     *
     * @return Collection<int, KitchenOrder>
     */
    public function unpaidFor(Booking|WalkinSession $bill): Collection
    {
        return $this->unpaid($bill)->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Whether the bill still owes for an accepted order. A bill that does
     * is not settled, however its own payment stands — paid, or covered by
     * the plan.
     */
    public function owesFor(Booking|WalkinSession $bill): bool
    {
        return $this->unpaid($bill)->exists();
    }

    /**
     * Whether the bill owes nothing for itself: paid, covered by its plan,
     * or free. What is left on it, if anything, is its orders — the one
     * rule closing, listing and paying a bill all agree on.
     */
    public function owesNothingItself(Booking|WalkinSession $bill): bool
    {
        if ($bill->payment_state === PaymentState::Paid) {
            return true;
        }

        return $bill instanceof Booking
            ? (int) $bill->price_points <= $bill->pointsPaid()
            : (float) $bill->amount_owed <= 0;
    }

    /**
     * The bill's orders as its cards show them beside its own price: how
     * many were accepted (`count` counts orders), how many items those
     * orders hold (`items_count`, every line's quantity), what they come to,
     * and what of that is unpaid.
     *
     * @return array{count: int, items_count: int, total_points: int, unpaid_points: int}
     */
    public function summaryFor(Booking|WalkinSession $bill): array
    {
        $orders = $this->owed($bill)->withSum('lines', 'quantity')->get();

        return [
            'count' => $orders->count(),
            'items_count' => (int) $orders->sum('lines_sum_quantity'),
            'total_points' => (int) $orders->sum('total_points'),
            'unpaid_points' => (int) $orders->whereNull('paid_at')->sum('total_points'),
        ];
    }

    /**
     * summaryFor() of a bill with no orders, for a card that has no bill yet.
     *
     * @return array{count: int, items_count: int, total_points: int, unpaid_points: int}
     */
    public static function emptySummary(): array
    {
        return ['count' => 0, 'items_count' => 0, 'total_points' => 0, 'unpaid_points' => 0];
    }

    /**
     * Every order sent to the bill whatever its status — still waiting,
     * being made, delivered, rejected or cancelled — oldest first, with its
     * lines and what the member's order view reads, so the bill can show
     * each order's items and follow its status. Unlike owedOn(), not only
     * what is owed.
     *
     * @return Collection<int, KitchenOrder>
     */
    public function allOn(Booking|WalkinSession $bill): Collection
    {
        return KitchenOrder::query()
            ->where('billable_type', $bill->getMorphClass())
            ->where('billable_id', $bill->id)
            ->with(['lines.item', 'seatDesk', 'space', 'acceptedPayment'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Each unpaid order paid at the desk with `$method`, exactly what it
     * costs, as the bill is. All or nothing: anything refused — an order
     * paid meanwhile — throws, and undoes the orders settled before it (and,
     * inside a caller's transaction, the rest with them).
     *
     * @return Collection<int, KitchenOrder> the orders it settled
     */
    public function settleAtReception(Booking|WalkinSession $bill, PaymentMethod $method, User $operator): Collection
    {
        return DB::transaction(function () use ($bill, $method, $operator) {
            $orders = $this->unpaidFor($bill);

            foreach ($orders as $order) {
                $this->payments->settleAtReception(PaymentPurpose::KitchenOrder, $order, $method, null, null, $operator);
            }

            return $orders;
        });
    }

    /**
     * Every unpaid order paid from the member's own general points, or none:
     * what the member asks for when only the orders are left to pay on a
     * bill. Short, it throws InsufficientPointsException for all of them
     * together — what the wallet held, and what they come to — and nothing
     * is paid.
     */
    public function payAllFromWallet(Booking|WalkinSession $bill): void
    {
        $orders = $this->unpaidFor($bill);
        $total = (int) $orders->sum('total_points');
        $paid = 0;

        try {
            DB::transaction(function () use ($bill, $orders, &$paid) {
                foreach ($orders as $order) {
                    $this->startWalletPayment($bill, $order);
                    $paid += (int) $order->total_points;
                }
            });
        } catch (InsufficientPointsException $e) {
            throw InsufficientPointsException::for($e->current + $paid, $total);
        }
    }

    /**
     * The bill paid by `$payBill` from the wallet together with its unpaid
     * orders, the orders first — so the bill's paid event finds nothing
     * owing on it. Should the orders take points the bill needed, the bill
     * comes first instead: it is paid alone, and the orders as far as what
     * is left goes. Whatever `$payBill` refuses undoes the orders with it.
     *
     * Which wallet pays the bill is settled before the orders spend
     * anything (billWallet()), and `$payBill` is handed it: the orders come
     * from the member's own points, and emptying those must never leave a
     * company wallet the only one left — paying the bill from it unasked.
     *
     * @template T
     *
     * @param  array{wallet_owner_type?: mixed, wallet_owner_id?: mixed}  $walletOwner  the wallet the member named, if any
     * @param  Closure(array{wallet_owner_type: mixed, wallet_owner_id: int|null}): T  $payBill
     * @return T
     *
     * @throws WalletChoiceRequiredException when several wallets could pay the bill and none was named, before anything is spent
     */
    public function payFromWalletWithBill(Booking|WalkinSession $bill, PaymentPurpose $purpose, array $walletOwner, Closure $payBill): mixed
    {
        $walletOwner = $this->billWallet($bill, $purpose, $walletOwner);

        try {
            return DB::transaction(function () use ($bill, $payBill, $walletOwner) {
                $this->payFromWallet($bill);

                return $payBill($walletOwner);
            });
        } catch (InsufficientPointsException) {
            // Rolled back: read the bill as it now is again, so what paying
            // it writes (the method chosen) is written again.
            $bill->refresh();

            $result = $payBill($walletOwner);
            $this->payFromWallet($bill);

            return $result;
        }
    }

    /**
     * The wallet the bill is paid from, by the rules the wallet payment
     * itself uses (WalletMethod::resolveWallet) as they stand before any
     * order is paid: the one named; else the only one with points; else, with
     * several, the member is asked to choose. With none holding points it is
     * left unnamed, for the bill's own payment to refuse as it always has.
     *
     * @param  array{wallet_owner_type?: mixed, wallet_owner_id?: mixed}  $walletOwner
     * @return array{wallet_owner_type: mixed, wallet_owner_id: int|null}
     */
    private function billWallet(Booking|WalkinSession $bill, PaymentPurpose $purpose, array $walletOwner): array
    {
        $type = $walletOwner['wallet_owner_type'] ?? null;
        $id = $walletOwner['wallet_owner_id'] ?? null;

        if ($type !== null && $id !== null) {
            return ['wallet_owner_type' => $type, 'wallet_owner_id' => (int) $id];
        }

        try {
            $wallet = app(WalletMethod::class)->resolveWallet($bill->user, $this->fulfillers->for($purpose)->walletCategory(), 0);
        } catch (InsufficientPointsException) {
            return ['wallet_owner_type' => null, 'wallet_owner_id' => null];
        }

        return ['wallet_owner_type' => $wallet->owner_type, 'wallet_owner_id' => (int) $wallet->owner_id];
    }

    /**
     * Each unpaid order paid from the member's own general points, oldest
     * first, as far as they go. An order the wallet cannot cover stays owed
     * — on the desk's outstanding items once the bill has closed — and the
     * bill is never held back by it.
     */
    public function payFromWallet(Booking|WalkinSession $bill): void
    {
        $member = $bill->user;

        if ($member === null) {
            return;
        }

        foreach ($this->unpaidFor($bill) as $order) {
            try {
                // A savepoint: a wallet that falls short undoes only this
                // order's payment, never the bill's closure around it.
                DB::transaction(fn () => $this->startWalletPayment($bill, $order));
            } catch (Throwable $e) {
                // Short of points is expected; anything else is not.
                Log::log($e instanceof InsufficientPointsException ? 'info' : 'warning', '[Kitchen] an order could not be paid from the wallet', [
                    'kitchen_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** The member's own wallet, never a company's they can spend — as for the bill's close (ClosedBookingSettlement). */
    private function startWalletPayment(Booking|WalkinSession $bill, KitchenOrder $order): void
    {
        $this->payments->start(PaymentPurpose::KitchenOrder, $bill->user, WalletMethod::KEY, [
            'payable' => $order,
            'wallet_owner_type' => OwnerType::User,
            'wallet_owner_id' => $bill->user_id,
        ]);
    }

    /**
     * Accepted, and costing something: an order that costs nothing is owed
     * nothing, as the fulfiller refuses to charge it.
     *
     * @return Builder<KitchenOrder>
     */
    private function owed(Booking|WalkinSession $bill): Builder
    {
        return KitchenOrder::query()
            ->where('billable_type', $bill->getMorphClass())
            ->where('billable_id', $bill->id)
            ->whereIn('status', KitchenOrderStatus::owedValues())
            ->where('total_points', '>', 0);
    }

    /** @return Builder<KitchenOrder> */
    private function unpaid(Booking|WalkinSession $bill): Builder
    {
        return $this->owed($bill)->whereNull('paid_at');
    }
}

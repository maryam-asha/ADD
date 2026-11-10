<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Membership\Enums\OwnerType;
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
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * The bill's accepted orders, paid or not, oldest first — the report's
     * `order` lines.
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
     * The bill's orders as its cards show them beside its own price: how
     * many were accepted, what they come to, and what of that is unpaid.
     *
     * @return array{count: int, total_points: int, unpaid_points: int}
     */
    public function summaryFor(Booking|WalkinSession $bill): array
    {
        $orders = $this->owedOn($bill);

        return [
            'count' => $orders->count(),
            'total_points' => (int) $orders->sum('total_points'),
            'unpaid_points' => (int) $orders->whereNull('paid_at')->sum('total_points'),
        ];
    }

    /**
     * Each unpaid order paid at the desk with `$method`, exactly what it
     * costs, as the bill is. All or nothing: anything refused — an order
     * paid meanwhile — throws, and undoes the orders settled before it (and,
     * inside a caller's transaction, the rest with them).
     */
    public function settleAtReception(Booking|WalkinSession $bill, PaymentMethod $method, User $operator): void
    {
        DB::transaction(function () use ($bill, $method, $operator) {
            foreach ($this->unpaidFor($bill) as $order) {
                $this->payments->settleAtReception(PaymentPurpose::KitchenOrder, $order, $method, null, null, $operator);
            }
        });
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
                DB::transaction(fn () => $this->payments->start(PaymentPurpose::KitchenOrder, $member, WalletMethod::KEY, [
                    'payable' => $order,
                    'wallet_owner_type' => OwnerType::User,
                    'wallet_owner_id' => $bill->user_id,
                ]));
            } catch (Throwable $e) {
                Log::info('[Kitchen] an order could not be paid from the wallet', [
                    'kitchen_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return Builder<KitchenOrder> */
    private function owed(Booking|WalkinSession $bill): Builder
    {
        return KitchenOrder::query()
            ->where('billable_type', $bill->getMorphClass())
            ->where('billable_id', $bill->id)
            ->whereIn('status', KitchenOrderStatus::owedValues());
    }

    /** @return Builder<KitchenOrder> */
    private function unpaid(Booking|WalkinSession $bill): Builder
    {
        return $this->owed($bill)->whereNull('paid_at')->where('total_points', '>', 0);
    }
}

<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\OrderRouting;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Database\Eloquent\Model;

/**
 * What the app shows before a member pays for something: how much it costs
 * in points, and every enabled method that can pay for it — the unavailable
 * ones first, then in config order, so a gateway added to
 * config/payments.php appears here with no other change — each saying whether it can pay right now and, if not, why, and
 * `hint`: what the member does next, when the label alone does not say.
 *
 * Cash cannot pay for a top-up or a plan from the app, but reception takes
 * it for both (admin/reception/wallet-top-ups, admin/reception/plan-purchases),
 * so it is listed for them unavailable, with a hint sending the member to the
 * desk, rather than left out. Starting a payment still refuses it
 * (CashMethod::supports()).
 *
 * The wallet method also lists the wallets the member may spend from, each
 * with its usable balance and whether it covers the amount, so the app can
 * offer the choice WalletChoiceRequiredException would otherwise ask for.
 */
class PaymentMethodOptions
{
    /** A kitchen order put on its open bill: OrderPaymentChoice::Bill, no driver. */
    public const BILL = 'bill';

    public function __construct(
        private readonly PaymentMethodRegistry $methods,
        private readonly FulfillerRegistry $fulfillers,
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
        private readonly OrderRouting $routing,
    ) {}

    /**
     * `$purpose` null lists every enabled method, for a screen that has not
     * decided what is being paid for yet. `$member` null (no login) lists the
     * methods without any balance: nothing is priced for a guest but a plan,
     * whose price is public.
     *
     * @return array{amount_points: int|null, methods: list<array<string, mixed>>}
     */
    public function for(?PaymentPurpose $purpose, ?User $member, ?Model $payable = null, ?Plan $plan = null, ?int $points = null): array
    {
        // `$points`: a price the caller already quoted — with a coupon the
        // member is about to use (CouponPreview).
        $points ??= $this->pointsDue($purpose, $member, $payable, $plan);
        $newOrder = $this->newKitchenOrderMethods($purpose, $member, $payable);

        $methods = [];

        foreach ($this->methods->enabled() as $key => $driver) {
            $option = ['key' => $key, 'label' => $driver->label(), 'available' => true, 'reason' => null, 'hint' => null];

            if ($purpose !== null && ! $driver->supports($purpose)) {
                if (! $this->paidAtReceptionOnly($key, $purpose)) {
                    continue;
                }

                $option['available'] = false;
                $option['reason'] = __('api.payment.reception_only');
                $option['hint'] = __('api.payment.reception_only_hints.'.$purpose->value);
            } elseif ($key === CashMethod::KEY && $this->sessionInProgress($payable)) {
                $option['available'] = false;
                $option['reason'] = __('api.payment.session_in_progress');
                $option['hint'] = __('api.payment.session_in_progress_hint');
            } elseif ($key === CashMethod::KEY && ($this->heldOffTheReceptionRoute($payable) || ($newOrder !== null && ! in_array(OrderPaymentChoice::Cash->value, $newOrder, true)))) {
                $option['available'] = false;
                $option['reason'] = __('api.payment.kitchen_order_no_cash');
            } elseif ($key === WalletMethod::KEY) {
                $option = $this->walletOption($option, $purpose, $member, $points);
            }

            $methods[] = $option;
        }

        if ($this->billCanTakeIt($payable) || ($newOrder !== null && in_array(OrderPaymentChoice::Bill->value, $newOrder, true))) {
            array_unshift($methods, ['key' => self::BILL, 'label' => __('api.payment.methods.bill'), 'available' => true, 'reason' => null, 'hint' => null]);
        }

        // The unavailable ones first, each group still in config order
        // (usort is stable).
        usort($methods, fn (array $a, array $b) => $a['available'] <=> $b['available']);

        return ['amount_points' => $points, 'methods' => $methods];
    }

    /** Cash for a top-up or a plan: the desk takes it, the app does not. */
    private function paidAtReceptionOnly(string $key, PaymentPurpose $purpose): bool
    {
        return $key === CashMethod::KEY
            && in_array($purpose, [PaymentPurpose::WalletTopUp, PaymentPurpose::PlanPurchase], true);
    }

    /**
     * A booking checked in and not yet checked out: reception settles it at
     * checkout, so the app has no cash to promise — the member who wants to
     * pay now goes to the desk. (An open walk-in never gets here: it has no
     * price until checkout, so quote() refuses it.)
     */
    private function sessionInProgress(?Model $payable): bool
    {
        return $payable instanceof Booking
            && $payable->checked_in_at !== null
            && $payable->checked_out_at === null;
    }

    /**
     * A kitchen order not sent yet (no `payable_id`): the methods the kitchen
     * menu offers (OrderRouting, the one source of both lists) — `bill`
     * while the member has a session or checked-in booking open, `cash` on
     * the reception route only. Which bill, and so where the order goes, is
     * the menu's `bills`; null for anything else.
     *
     * @return list<string>|null
     */
    private function newKitchenOrderMethods(?PaymentPurpose $purpose, ?User $member, ?Model $payable): ?array
    {
        if ($purpose !== PaymentPurpose::KitchenOrder || $member === null || $payable !== null) {
            return null;
        }

        return $this->routing->for($member, null, null)->methods;
    }

    /**
     * A kitchen order held for its card whose bill is still open may be put
     * on that bill instead (KitchenOrderService::methodsFor()). Not a driver:
     * nothing is paid now, so the option is only ever sent back as
     * `payment.method` on POST member/kitchen-orders with `order_id`.
     */
    private function billCanTakeIt(?Model $payable): bool
    {
        if (! $payable instanceof KitchenOrder
            || $payable->status !== KitchenOrderStatus::AwaitingPayment
            || ! $payable->hasBill()) {
            return false;
        }

        $bill = $payable->billable;

        return ($bill instanceof Booking || $bill instanceof WalkinSession) && $bill->checked_out_at === null;
    }

    /**
     * A kitchen order held for its card, sent to the kitchen rather than to
     * reception: nobody at a desk takes its cash, so sending it again with
     * cash is refused (KitchenOrderService::methodsFor()).
     */
    private function heldOffTheReceptionRoute(?Model $payable): bool
    {
        return $payable instanceof KitchenOrder
            && $payable->status === KitchenOrderStatus::AwaitingPayment
            && $payable->route !== OrderRoute::Reception;
    }

    private function pointsDue(?PaymentPurpose $purpose, ?User $member, ?Model $payable, ?Plan $plan): ?int
    {
        // A top-up has no price: the member names the amount.
        if ($purpose === null || $purpose === PaymentPurpose::WalletTopUp) {
            return null;
        }

        // A record is only priced for the member it belongs to.
        if ($payable === null && $plan === null) {
            return null;
        }

        if ($payable !== null && $member === null) {
            return null;
        }

        return $this->payments->quote($purpose, $payable, $plan);
    }

    /**
     * The wallet's balance — the most the member can spend from any wallet
     * they may use, in the purpose's pool (general when none) — and, with
     * an amount, whether it covers it. Without a member: no balance.
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function walletOption(array $option, ?PaymentPurpose $purpose, ?User $member, ?int $points): array
    {
        if ($member === null) {
            return $option + ['balance' => null];
        }

        $category = $purpose === null ? WalletTransactionCategory::General : $this->fulfillers->for($purpose)->walletCategory();

        $wallets = collect($this->wallets->spendOptions($member, $category))
            // A plan paid from a wallet goes to that wallet's owner; bought
            // from the app it is the member's own.
            ->when($purpose === PaymentPurpose::PlanPurchase, fn ($c) => $c->where('owner_type', OwnerType::User->value))
            ->map(fn (array $wallet) => [
                'owner_type' => $wallet['owner_type'],
                'owner_id' => $wallet['owner_id'],
                'owner_label' => $wallet['owner_label'],
                'usable_balance' => $wallet['usable_balance'],
                'sufficient' => $wallet['usable_balance'] >= (int) $points,
            ])
            ->values()
            ->all();

        $best = (int) max(array_column($wallets, 'usable_balance') ?: [0]);

        if ($best <= 0) {
            $option['available'] = false;
            $option['reason'] = __('api.payment.no_balance');
        } elseif ($points !== null && $best < $points) {
            $option['available'] = false;
            $option['reason'] = __('api.payment.insufficient_points', [
                'current' => $best,
                'required' => $points,
                'shortage' => $points - $best,
            ]);
        }

        return $option + ['balance' => $best, 'wallets' => $wallets];
    }
}

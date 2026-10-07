<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Pricing\Services\PriceQuoteService;
use Illuminate\Support\Facades\DB;

/**
 * Buying a plan: the points are spent from the paying wallet's general
 * balance, and the plan is activated for that wallet's owner — the member,
 * or the company when a company admin buys for it.
 *
 * The price is re-read from the plan every time, never taken from the
 * payment: a plan repriced (or withdrawn) between opening the payment and
 * settling it is caught here rather than granted at the old price.
 */
class PlanPurchaseFulfiller implements PaymentFulfiller
{
    public function __construct(
        private readonly MembershipActivationService $activations,
        private readonly DedicatedDeskService $desks,
        private readonly PaymentMethodRegistry $methods,
        private readonly PriceQuoteService $quotes,
    ) {}

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::PlanPurchase;
    }

    /**
     * The plan as it is now — a repriced plan is caught — at the offer and
     * the coupon locked on the payment when it was opened
     * (PriceQuoteService, the one place a price is composed).
     */
    public function pointsDue(Payment $payment): int
    {
        return $this->quotes->forPlanPayment($payment, $this->plan($payment))->finalPoints;
    }

    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::General;
    }

    /**
     * The wording MembershipActivationService used when it charged for the
     * plan itself, so a plan bought through a payment reads the same in the
     * wallet history.
     */
    public function describe(Payment $payment): string
    {
        return "Membership purchase: plan #{$payment->plan_id}";
    }

    public function assertPayable(Payment $payment): void
    {
        if (! $this->plan($payment)->is_active) {
            throw new ReceptionActionException('api.payment.plan_unavailable');
        }
    }

    /**
     * `charge: false` because PaymentService has already spent the points;
     * activation only creates the membership, holds its seats and grants
     * its balances. The audit entry still says `charged: true` — the member
     * did pay for it, just not inside activation.
     */
    public function fulfil(Payment $payment): void
    {
        $plan = $this->plan($payment);
        $wallet = $payment->wallet;

        $membership = $this->activations->activate(
            $plan,
            $wallet->owner_type,
            (int) $wallet->owner_id,
            charge: false,
            auditAction: $this->auditAction($payment),
            // What was actually paid, a sale or coupon included — not the
            // plan's list price activation would otherwise record.
            auditProperties: ['charged' => true, 'payment_id' => $payment->id, 'price_points' => (int) $payment->amount_points],
        );

        // Activation tells staff about a desk-less Dedicated Desk plan only
        // when it charged — its sign that a member bought it rather than
        // staff arranging it. A payment is always a member's purchase, and
        // nobody has picked the desk, so staff are told here instead. After
        // commit, like activation's own notice, so a rolled-back sale tells
        // nobody.
        if ($plan->isDedicatedDesk()) {
            DB::afterCommit(fn () => $this->desks->notifyStaffDeskNeeded($membership->setRelation('plan', $plan)));
        }

        $payment->forceFill(['membership_id' => $membership->id])->save();
    }

    /**
     * Read fresh from the database on every call, never from a loaded
     * relation, so the price and availability are the plan's current ones.
     */
    private function plan(Payment $payment): Plan
    {
        $plan = $payment->plan_id === null ? null : Plan::query()->find($payment->plan_id);

        if ($plan === null) {
            throw new ReceptionActionException('api.payment.plan_unavailable');
        }

        return $plan;
    }

    /**
     * How the purchase is named in the audit log, from how it was paid:
     * money taken by hand at the desk, a card through a gateway, or points
     * the member already held.
     */
    private function auditAction(Payment $payment): string
    {
        if ($payment->collected_via !== null || $payment->received_by !== null) {
            return 'membership_purchased_at_reception';
        }

        if (in_array((string) $payment->method, $this->methods->gatewayKeys(), true)) {
            return 'membership_purchased_by_card';
        }

        return 'membership_purchased';
    }
}

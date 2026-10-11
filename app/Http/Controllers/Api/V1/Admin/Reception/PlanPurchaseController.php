<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StorePlanPurchaseRequest;
use App\Http\Resources\Finance\PaymentResource;
use App\Http\Resources\MembershipResource;
use App\Support\Presenters\AppliedDiscountPresenter;
use App\Support\Presenters\CouponOutcomePresenter;
use Illuminate\Http\JsonResponse;

/**
 * Reception selling a plan from the dashboard — WalletTopUpController's
 * shape, for a plan (docs/decisions/unified-payments.md, "Plans sold at
 * reception"). Paid in cash, the desk types no amount: it takes the plan's
 * price exactly, in the currency chosen, and the money becomes points in the
 * owner's wallet. Paid from `wallet`, the points the owner already holds pay
 * for it when they cover it. Either way they are spent on the plan at once
 * (PaymentService::sellPlanAtReception), so nothing is ever held unpaid; a
 * wallet that falls short answers with the shape every pay-with-points path
 * gives (`current`, `required`, `shortage`).
 *
 * A member's plan takes the coupon they brought (`coupon_code`), checked and
 * priced as the app checks it; a code that cannot be used answers 422
 * `{message, reason}`, as everywhere. Both responses say what priced the
 * plan: the preview the running `offer` and the `coupon`'s outcome, the
 * receipt the `discounts` locked on the payment.
 *
 * POST admin/memberships stays the admin-only grant of a plan paid for
 * outside the app; this is the sale.
 */
class PlanPurchaseController extends Controller
{
    use LogsSensitiveActions;

    public function store(
        StorePlanPurchaseRequest $request,
        PaymentService $payments,
        WalletService $wallets,
        MembershipResolver $memberships,
        CouponEligibility $coupons,
        CouponLedger $ledger,
        CouponOutcomePresenter $outcomes,
        AppliedDiscountPresenter $discounts,
    ): JsonResponse {
        $plan = Plan::query()->findOrFail($request->validated('plan_id'));
        [$ownerType, $ownerId] = $this->owner($request);
        $code = $request->validated('coupon_code');
        $coupon = $code !== null ? $coupons->resolve($code) : null;

        if ($memberships->activeForOwner($ownerType, $ownerId) !== null) {
            return response()->json(['message' => __('api.admin.membership_already_active')], 422);
        }

        // As reception's other settlements: an account that never had a
        // wallet opened still pays cash — the money needs one to pass through.
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        $previousBalancePoints = $wallets->generalBalance($wallet);
        $owner = $ownerType === OwnerType::Company ? Company::query()->find($ownerId) : User::query()->find($ownerId);
        // A member pays for their own plan; a company's, whoever took the
        // money — as a reception top-up records it.
        $payer = $owner instanceof User ? $owner : $request->user();
        $fromWallet = $request->validated('payment_method') === WalletMethod::KEY;

        try {
            $settled = $payments->sellPlanAtReception(
                $plan,
                $wallet,
                $payer,
                $fromWallet ? null : PaymentMethod::from($request->validated('payment_method')),
                $fromWallet ? null : $request->validated('currency'),
                $request->user(),
                $coupon,
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (SeatPoolExhaustedException $e) {
            return response()->json([
                'message' => __('api.membership.seat_pool_full'),
                'first_shortfall_at' => $e->firstShortfall->toIso8601String(),
            ], 422);
        } catch (PaymentPricingException) {
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        }

        $payment = $settled->payment;
        $membership = Membership::query()->with(['plan', 'seatDesks'])->findOrFail($payment->membership_id);
        $credit = $payment->wallet_transaction_id === null ? null : WalletTransaction::query()->find($payment->wallet_transaction_id);

        $this->logSensitiveAction('plan_sold_at_reception', $payment, [
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
            'plan_id' => $plan->id,
            'membership_id' => $membership->id,
            'points_charged' => $settled->pointsCharged,
            'payment_amount' => $payment->received_amount,
            'payment_currency' => $payment->received_currency,
            'payment_method' => $request->validated('payment_method'),
            'coupon_code' => $coupon?->code,
        ]);

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'transaction_code' => sprintf('PLN-%s-%04d', now()->format('Ymd'), $payment->id),
                'plan_id' => $plan->id,
                'plan_name' => $plan->translate('name'),
                'list_points' => (int) $payment->list_price_points,
                'points_charged' => $settled->pointsCharged,
                'points_credited' => $settled->pointsCredited,
                'previous_balance_points' => $previousBalancePoints,
                'new_balance_points' => $previousBalancePoints + $settled->changePoints(),
                'payment_amount' => $payment->received_amount === null ? null : (string) $payment->received_amount,
                'payment_currency' => $payment->received_currency,
                'exchange_rate' => $credit?->exchange_rate_to_base === null ? null : (string) $credit->exchange_rate_to_base,
                'point_rate_usd' => $credit?->point_rate_usd === null ? null : (string) $credit->point_rate_usd,
                'payment_method' => $request->validated('payment_method'),
                'performed_by_user_id' => $request->user()->id,
                'performed_by_name' => $request->user()->name,
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'recipient_name' => $owner instanceof Company ? $owner->legal_name : $owner?->name,
                'recipient_phone' => $owner instanceof Company ? $owner->contract_ref : $owner?->phone,
                // What priced it, as locked on the payment: the offer, the coupon.
                'discounts' => $discounts->for($payment),
                'coupon' => $code !== null ? $outcomes->present($code, $ledger->couponFor($payment)) : null,
                'membership' => new MembershipResource($membership),
                'payment' => new PaymentResource($payment),
                'created_at' => $payment->created_at?->toISOString() ?? now()->toISOString(),
            ],
        ], 201);
    }

    /**
     * What the sale would take, without writing anything — the plan's price
     * now (a running offer included), that price in the currency chosen, by
     * the same conversion store() collects with, and whether the owner's
     * points cover it (`wallet_covers`): when they do, the desk may offer to
     * pay from them. `offer` names the running offer that priced it, if
     * one did; `coupon` says whether the code sent was used, or the offer
     * was better.
     */
    public function preview(
        StorePlanPurchaseRequest $request,
        PriceQuoteService $quotes,
        ReceivedMoneyConverter $converter,
        CurrencyConversionService $conversion,
        WalletService $wallets,
        CouponEligibility $coupons,
        CouponOutcomePresenter $outcomes,
    ): JsonResponse {
        $plan = Plan::query()->findOrFail($request->validated('plan_id'));
        [$ownerType, $ownerId] = $this->owner($request);
        $currency = $request->validated('currency') ?? $conversion->baseCurrencyCode();
        $code = $request->validated('coupon_code');

        $member = $ownerType === OwnerType::User ? User::query()->find($ownerId) : null;
        $quote = $quotes->forPlan($plan, $member, $code !== null ? $coupons->resolve($code) : null, now());
        $offer = $quote->appliedOffer();

        $wallet = Wallet::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();
        $balance = $wallet === null ? 0 : $wallets->generalBalance($wallet);

        try {
            $amount = $converter->pointsToAmount($quote->finalPoints, $currency);
        } catch (PaymentPricingException) {
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        }

        return response()->json([
            'data' => [
                'plan_id' => $plan->id,
                'list_points' => $quote->listPoints,
                'points' => $quote->finalPoints,
                'payment_amount' => $amount,
                'payment_currency' => $currency,
                'balance_points' => $balance,
                'wallet_covers' => $balance >= $quote->finalPoints,
                'offer' => $offer === null ? null : [
                    'name' => $offer->translate('name') ?: null,
                    'discount_type' => $offer->discount_type->value,
                    'discount_value' => $offer->discount_value,
                    'saved_points' => $quote->automaticPoints,
                    'ends_at' => $offer->ends_at?->toIso8601String(),
                ],
                'coupon' => $code !== null ? $outcomes->fromQuote($code, $quote) : null,
            ],
        ]);
    }

    /**
     * @return array{0: OwnerType, 1: int}
     */
    private function owner(StorePlanPurchaseRequest $request): array
    {
        return $request->validated('company_id')
            ? [OwnerType::Company, (int) $request->validated('company_id')]
            : [OwnerType::User, (int) $request->validated('user_id')];
    }
}

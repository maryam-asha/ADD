<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\StartPayment;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\PackageBrowseService;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Http\Controllers\Concerns\RespondsToGatewayFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMembershipRequest;
use App\Http\Resources\Finance\PaymentResource;
use App\Http\Resources\MembershipResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * docs/architecture/2026-08-08-backend-build-plan.md ("Phase 3 — Membership")
 * — buying a plan for the member, or for a company they administer. Same
 * "self-service checked via the policy in the controller" shape as
 * CompanyWalletAllocationController.
 *
 * How it is paid (docs/superpowers/plans/2026-09-30-unified-payments.md,
 * Task 6): points from the owner's wallet now (the default, and the only
 * way before payment methods existed); cash at the desk, held as a pending
 * purchase — no membership — until reception takes the money; or a card,
 * for the member's own plan, which answers with where to pay.
 */
class MembershipController extends Controller
{
    use RespondsToGatewayFailures;

    /**
     * The member's own active plan and this-cycle usage, for the "My
     * Subscription" screen — see `PackageController::index()` for the
     * separate package-catalog endpoint. `data: null` (still 200) when the
     * member has no active membership — a normal state, not an error.
     *
     * `pending_purchase` sits beside `data`, not inside it: a plan held for
     * cash is not a membership, and `data` keeps its shape for old apps.
     */
    public function current(Request $request, PackageBrowseService $service): JsonResponse
    {
        $pending = $this->pendingCashPurchase($request->user());

        return response()->json([
            'data' => $service->getCurrentSubscription($request->user()),
            'pending_purchase' => $pending === null ? null : new PaymentResource($pending),
        ]);
    }

    public function store(
        StoreMembershipRequest $request,
        PaymentService $payments,
        StartPayment $card,
        CouponEligibility $coupons,
        PriceQuoteService $quotes,
    ): JsonResponse {
        $plan = Plan::findOrFail($request->validated('plan_id'));
        $member = $request->user();

        if ($request->validated('company_id')) {
            $company = Company::findOrFail($request->validated('company_id'));

            Gate::authorize('manageMembers', $company);

            $ownerType = OwnerType::Company;
            $ownerId = $company->id;
        } else {
            $ownerType = OwnerType::User;
            $ownerId = $member->id;
        }

        // A coupon is checked — and its price known — before any method is
        // chosen: one that makes the plan free settles it at once, whatever
        // the member asked to pay with.
        $code = $request->validated('coupon_code');
        $coupon = $code !== null ? $coupons->resolve($code) : null;
        $free = $coupon !== null && $quotes->forPlan($plan, $member, $coupon, now())->finalPoints === 0;

        return match ($free ? WalletMethod::KEY : ($request->validated('payment_method') ?? WalletMethod::KEY)) {
            CashMethod::KEY => $this->holdForCash($payments, $member, $plan, $ownerType, $ownerId, $coupon),
            PaymeraGateway::KEY => $this->startCard($card, $member, $plan, $ownerType, app()->getLocale(), $coupon),
            default => $this->payWithPoints($payments, $member, $plan, $ownerType, $ownerId, $coupon),
        };
    }

    private function payWithPoints(PaymentService $payments, User $member, Plan $plan, OwnerType $ownerType, int $ownerId, ?Coupon $coupon = null): JsonResponse
    {
        try {
            ['payment' => $payment] = $payments->start(PaymentPurpose::PlanPurchase, $member, WalletMethod::KEY, array_filter([
                'plan' => $plan,
                'wallet_owner_type' => $ownerType,
                'wallet_owner_id' => $ownerId,
                'coupon' => $coupon,
            ]));
        } catch (InsufficientPointsException) {
            // The message this endpoint has always given, without the
            // numbers the newer payment endpoints add.
            abort(422, __('api.wallet.insufficient_balance_for_plan'));
        } catch (SeatPoolExhaustedException $e) {
            // Every co-working seat in the branch is held for part of the
            // plan's period (docs/decisions/coworking-seat-pool.md); nothing
            // was charged.
            throw new HttpResponseException(response()->json([
                'message' => __('api.membership.seat_pool_full'),
                'first_shortfall_at' => $e->firstShortfall->toIso8601String(),
            ], 422));
        }

        $membership = Membership::query()->findOrFail($payment->membership_id);

        // 201 as before, when the resource was built from the model activation
        // had just created.
        return (new MembershipResource($membership->load(['plan', 'seatDesks'])))->response()->setStatusCode(201);
    }

    /**
     * Asking again for the same plan and owner while a hold is open returns
     * that hold rather than a second one.
     *
     * The hold is opened on the owner's wallet — the company's, for a
     * company purchase — handed to the payment as already authorized: this
     * endpoint checked CompanyPolicy::manageMembers above, which the cash
     * method itself cannot.
     */
    private function holdForCash(PaymentService $payments, User $member, Plan $plan, OwnerType $ownerType, int $ownerId, ?Coupon $coupon = null): JsonResponse
    {
        $wallet = Wallet::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();

        if ($wallet === null && $ownerType === OwnerType::Company) {
            return response()->json(['message' => __('api.payment.no_wallet')], 422);
        }

        // A coupon opens a hold of its own, at its price.
        $existing = $wallet === null || $coupon !== null ? null : $this->openCashHolds()
            ->where('plan_id', $plan->id)
            ->where('wallet_id', $wallet->id)
            ->latest('id')
            ->first();

        $payment = $existing ?? $payments->start(PaymentPurpose::PlanPurchase, $member, CashMethod::KEY, array_filter([
            'plan' => $plan,
            'authorized_wallet' => $wallet,
            'coupon' => $coupon,
        ]))['payment'];

        return response()->json(['data' => ['pending_purchase' => new PaymentResource($payment)]], 201);
    }

    /**
     * The card flow the app already uses for plans, answered in the shape
     * every payment start now has. A card buys the member's own plan only:
     * its points land in the member's wallet, and the plan goes to that
     * wallet's owner.
     */
    private function startCard(StartPayment $card, User $member, Plan $plan, OwnerType $ownerType, ?string $lang, ?Coupon $coupon = null): JsonResponse
    {
        if ($ownerType !== OwnerType::User) {
            return response()->json(['message' => __('api.payment.method_not_supported')], 422);
        }

        try {
            ['payment' => $payment, 'url' => $url] = $card->handle(
                user: $member,
                purpose: PaymentPurpose::PlanPurchase,
                plan: $plan,
                lang: $lang,
                coupon: $coupon,
            );
        } catch (Throwable $e) {
            return $this->gatewayFailureResponse($e, $member->id) ?? throw $e;
        }

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($payment),
                'redirect_url' => $url,
                'next' => 'redirect',
            ],
        ], 201);
    }

    /**
     * The latest open cash hold the member placed — for themselves or for a
     * company they buy for.
     */
    private function pendingCashPurchase(User $member): ?Payment
    {
        return $this->openCashHolds()->where('user_id', $member->id)->latest('id')->first();
    }

    /**
     * @return Builder<Payment>
     */
    private function openCashHolds()
    {
        return Payment::query()
            ->where('method', CashMethod::KEY)
            ->where('purpose', PaymentPurpose::PlanPurchase)
            ->where('status', PaymentStatus::Pending)
            ->where('expires_at', '>', now());
    }
}

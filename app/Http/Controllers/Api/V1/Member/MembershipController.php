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
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PackageBrowseService;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Http\Controllers\Concerns\RespondsToGatewayFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ListMembershipsRequest;
use App\Http\Requests\Member\StoreMembershipRequest;
use App\Http\Resources\Finance\PaymentResource;
use App\Http\Resources\MembershipResource;
use App\Support\Presenters\CouponOutcomePresenter;
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
 * How it is paid (docs/decisions/unified-payments.md): there is no
 * subscription without payment. Points from the owner's wallet (the
 * default) subscribe at once, or not at all when they do not cover it; a
 * card, for the member's own plan, answers with where to pay and subscribes
 * only once the card is accepted (PlanPurchaseFulfiller). No cash.
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
     * Plans no longer take cash; this shows a hold opened before that until
     * reception settles it or it lapses (payments:expire-pending).
     */
    public function current(Request $request, PackageBrowseService $service): JsonResponse
    {
        $pending = $this->pendingCashPurchase($request->user());

        return response()->json([
            'data' => $service->getCurrentSubscription($request->user()),
            'pending_purchase' => $pending === null ? null : new PaymentResource($pending),
        ]);
    }

    /**
     * Every subscription the member holds or held — their own and those of
     * the companies they belong to — active or expired, newest first.
     * `via` says whose it is (`personal` | `company`, with the company named);
     * `status` is `upcoming` before `current_period_start` (a renewal made
     * ahead), `active` until `current_period_end`, then `expired`;
     * `is_current` marks the one memberships/current shows (the member's own
     * active plan first, else a company's), on no row once all have ended.
     * Filters: `status`, `via`, `per_page`.
     */
    public function index(ListMembershipsRequest $request, MembershipResolver $memberships): JsonResponse
    {
        $member = $request->user();
        $companies = $member->companies()->get()->keyBy('id');
        $currentId = $memberships->activeFor($member)?->id;

        $paginator = $memberships->heldBy($member)
            ->with(['plan', 'seatDesks'])
            ->when($request->validated('via'), fn (Builder $q, string $via) => $q->where(
                'owner_type',
                $via === 'company' ? OwnerType::Company : OwnerType::User,
            ))
            ->when($request->validated('status') === 'active', fn (Builder $q) => $q->live())
            ->when($request->validated('status') === 'expired', fn (Builder $q) => $q->ended())
            ->when($request->validated('status') === 'upcoming', fn (Builder $q) => $q->where('status', MembershipStatus::Active)->where('current_period_start', '>', now()))
            ->orderByDesc('current_period_start')
            ->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Membership $membership) => [
                'id' => $membership->id,
                'plan' => ['id' => $membership->plan_id, 'name' => $membership->plan?->translate('name')],
                'via' => $membership->owner_type === OwnerType::Company ? 'company' : 'personal',
                'company' => $membership->owner_type === OwnerType::Company
                    ? ['id' => $membership->owner_id, 'name' => $companies->get($membership->owner_id)?->legal_name]
                    : null,
                'status' => $membership->current_period_start->isFuture() ? 'upcoming' : ($membership->isLive() ? 'active' : 'expired'),
                'is_current' => $membership->id === $currentId,
                'seats' => $membership->seats,
                'desks' => $membership->seatDesks->map(fn ($desk) => ['code' => $desk->code, 'label' => $desk->label])->values(),
                'current_period_start' => $membership->current_period_start->toIso8601String(),
                'current_period_end' => $membership->current_period_end->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
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

        // A coupon is checked — and the price known — before any method is
        // chosen: a coupon or an offer that makes the plan free settles it
        // at once, whatever the member asked to pay with.
        $code = $request->validated('coupon_code');
        $coupon = $code !== null ? $coupons->resolve($code) : null;
        $free = $quotes->forPlan($plan, $member, $coupon, now())->finalPoints === 0;

        return match ($free ? WalletMethod::KEY : ($request->validated('payment_method') ?? WalletMethod::KEY)) {
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
        return (new MembershipResource($membership->load(['plan', 'seatDesks'])))
            ->additional($this->couponOutcome($coupon, $payment))
            ->response()
            ->setStatusCode(201);
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
        ] + $this->couponOutcome($coupon, $payment), 201);
    }

    /**
     * What became of the code sent with the purchase, beside `data` as on
     * POST bookings: used on the payment, or not because the plan's sale was
     * already lower (D2).
     *
     * @return array<string, array<string, mixed>>
     */
    private function couponOutcome(?Coupon $coupon, Payment $payment): array
    {
        return $coupon === null ? [] : [
            'coupon' => app(CouponOutcomePresenter::class)->present($coupon->code, app(CouponLedger::class)->couponFor($payment)),
        ];
    }

    /**
     * The latest open cash hold the member placed before plans stopped
     * taking cash — for themselves or for a company they bought for.
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

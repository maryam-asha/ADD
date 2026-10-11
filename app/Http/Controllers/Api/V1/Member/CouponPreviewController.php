<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\MemberPayables;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponPreview;
use App\Domain\Pricing\Services\MemberCoupons;
use App\Domain\Pricing\ValueObjects\PriceLine;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\PreviewCouponRequest;
use App\Http\Resources\MemberCouponResource;
use App\Support\Presenters\CouponOutcomePresenter;
use App\Support\Presenters\PriceLabelPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * POST member/coupons/preview — "Apply" in the app, in two steps, writing
 * nothing and spending nothing:
 *
 * 1. The check: does the code exist, is it live, may this member use it,
 *    and — given a purpose and an item — does it apply there. A code that
 *    cannot be used answers 422 `{message, reason}`; an unknown code and a
 *    switched-off one answer alike (D11).
 * 2. Given something that can be priced, the price: the same quote the
 *    purchase makes, so its minimum price and how it sits beside an offer
 *    are answered too.
 *
 * The response keeps one shape: `data.coupon` is the coupon's terms and
 * `uses_left`, with `applied` / `points_discounted` / `message` from the
 * quote; the price fields are null when nothing was priced.
 */
class CouponPreviewController extends Controller
{
    public function __invoke(
        PreviewCouponRequest $request,
        CouponEligibility $eligibility,
        MemberCoupons $coupons,
        CouponPreview $preview,
        MemberPayables $payables,
        CouponOutcomePresenter $outcomes,
        PriceLabelPresenter $prices,
    ): JsonResponse {
        $member = $request->user();
        $code = $request->validated('code');
        $coupon = $eligibility->resolve($code);

        if (($rejection = $eligibility->rejection($coupon, $member, $request->target())) !== null) {
            throw new CouponRejectedException($rejection);
        }

        $quote = $this->quote($request, $member, $coupon, $preview, $payables);
        $outcome = $quote !== null ? $outcomes->fromQuote($code, $quote) : null;

        $coupon->load('targets')->setAttribute('uses_left', $coupons->usesLeft($coupon, $member));

        return response()->json([
            'message' => $outcome['message'] ?? __('api.coupon.valid'),
            'data' => [
                'coupon' => (new MemberCouponResource($coupon))->resolve($request) + [
                    'applied' => $outcome['applied'] ?? null,
                    'points_discounted' => $outcome['points_discounted'] ?? null,
                    'message' => $outcome['message'] ?? null,
                ],
                'list_points' => $quote?->listPoints,
                'list_price' => $quote !== null ? $prices->total($quote->listPoints, $member)['price'] : null,
                'price_points' => $quote?->finalPoints,
                'price' => $quote !== null ? $prices->total($quote->finalPoints, $member)['price'] : null,
                'lines' => $quote !== null
                    ? array_map(fn (PriceLine $line) => ['key' => $line->key, 'points' => $line->points], $quote->lines())
                    : null,
            ],
        ]);
    }

    /**
     * The price, when the request names something that has one: a plan, an
     * unpaid record, a booking window or an event. Null otherwise.
     *
     * @throws CouponRejectedException
     */
    private function quote(PreviewCouponRequest $request, User $member, Coupon $coupon, CouponPreview $preview, MemberPayables $payables): ?PriceQuote
    {
        $purpose = $request->purpose();

        return match (true) {
            $request->filled('plan_id') => $preview->plan($member, $coupon, Plan::findOrFail($request->validated('plan_id'))),
            $request->filled('payable_id') => $preview->owed($member, $coupon, $payables->find(PaymentPurpose::from($purpose->value), (int) $request->validated('payable_id'), $member)),
            $purpose === PricingPurpose::Booking && $request->filled('start_at') => $preview->bookingWindow(
                $member,
                $coupon,
                Space::findOrFail($request->validated('space_id')),
                Carbon::parse($request->validated('start_at'), 'UTC'),
                Carbon::parse($request->validated('end_at'), 'UTC'),
            ),
            $request->filled('event_id') => $preview->event($member, $coupon, Event::findOrFail($request->validated('event_id'))),
            default => null,
        };
    }
}

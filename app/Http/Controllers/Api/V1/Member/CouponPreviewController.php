<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\MemberPayables;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponPreview;
use App\Domain\Pricing\ValueObjects\PriceLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\PreviewCouponRequest;
use App\Support\Presenters\CouponOutcomePresenter;
use App\Support\Presenters\PriceLabelPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * POST member/coupons/preview — "Apply" in the app: what a code would take
 * off what the member is about to buy, from the same quote the purchase
 * makes, writing nothing and spending nothing. A code that cannot be used
 * answers 422 with its reason.
 */
class CouponPreviewController extends Controller
{
    public function __invoke(
        PreviewCouponRequest $request,
        CouponEligibility $coupons,
        CouponPreview $preview,
        MemberPayables $payables,
        CouponOutcomePresenter $outcomes,
        PriceLabelPresenter $prices,
    ): JsonResponse {
        $member = $request->user();
        $code = $request->validated('code');
        $coupon = $coupons->resolve($code);
        $purpose = $request->purpose();
        $payableId = $request->validated('payable_id');

        $quote = match (true) {
            $purpose === PricingPurpose::PlanPurchase => $preview->plan($member, $coupon, Plan::findOrFail($request->validated('plan_id'))),
            $payableId !== null => $preview->owed($member, $coupon, $payables->find(PaymentPurpose::from($purpose->value), (int) $payableId, $member)),
            $purpose === PricingPurpose::Booking => $preview->bookingWindow(
                $member,
                $coupon,
                Space::findOrFail($request->validated('space_id')),
                Carbon::parse($request->validated('start_at'), 'UTC'),
                Carbon::parse($request->validated('end_at'), 'UTC'),
            ),
            default => $preview->event($member, $coupon, Event::findOrFail($request->validated('event_id'))),
        };

        return response()->json([
            'data' => [
                'coupon' => $outcomes->fromQuote($code, $quote),
                'list_points' => $quote->listPoints,
                'list_price' => $prices->total($quote->listPoints, $member)['price'],
                'price_points' => $quote->finalPoints,
                'price' => $prices->total($quote->finalPoints, $member)['price'],
                'lines' => array_map(fn (PriceLine $line) => ['key' => $line->key, 'points' => $line->points], $quote->lines()),
            ],
        ]);
    }
}

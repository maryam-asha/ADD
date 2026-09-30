<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\MemberPayables;
use App\Domain\Finance\Payments\PaymentMethodOptions;
use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ListPaymentMethodsRequest;
use App\Support\Presenters\PriceLabelPresenter;
use Illuminate\Http\JsonResponse;

/**
 * How the member can pay for something, before they pay: the amount and
 * each enabled payment method, with whether it can pay right now. Creates
 * nothing — POST member/payments (or the operation's own endpoint) does.
 */
class PaymentMethodsController extends Controller
{
    public function index(
        ListPaymentMethodsRequest $request,
        PaymentMethodOptions $options,
        MemberPayables $payables,
        PriceLabelPresenter $prices,
    ): JsonResponse {
        $member = $request->user();
        $purpose = $request->purpose();

        // Only a plan the member could actually buy — the catalog's filter.
        $plan = $purpose === PaymentPurpose::PlanPurchase
            ? Plan::query()->publiclyListable()->findOrFail($request->validated('plan_id'))
            : null;

        try {
            $result = $options->for(
                $purpose,
                $member,
                $payables->find($purpose, $request->validated('payable_id'), $member),
                $plan,
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $labels = $result['amount_points'] === null
            ? ['price' => null, 'price_point' => null]
            : $prices->total($result['amount_points'], $member);

        return response()->json([
            'data' => [
                'amount_points' => $result['amount_points'],
                'price' => $labels['price'],
                'price_point' => $labels['price_point'],
                'methods' => $result['methods'],
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\MemberPayables;
use App\Domain\Finance\Payments\PaymentMethodOptions;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Controller;
use App\Support\Presenters\PriceLabelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/payment-methods — the payment methods the app may offer, in
 * config/payments.php order, readable before login. A gateway added in
 * config appears here with no app change.
 *
 * The token is optional. With a member's token the wallet method carries the
 * member's balance (`balance`, and `wallets` with each wallet's usable
 * balance), is unavailable with a reason when there is nothing to spend,
 * and — given what is being paid for — says whether it covers it. Without
 * one, or with a token that is not a member's app token, nothing personal is
 * returned.
 *
 * `purpose` is optional: omitted lists every enabled method; given, only the
 * methods that can pay for it (a top-up: the card).
 */
class PaymentMethodsController extends Controller
{
    public function index(
        Request $request,
        PaymentMethodOptions $options,
        MemberPayables $payables,
        PriceLabelPresenter $prices,
    ): JsonResponse {
        $validated = $request->validate([
            'purpose' => ['nullable', Rule::in(['wallet_top_up', 'plan_purchase', 'booking', 'coworking_session', 'event_registration'])],
            'payable_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $member = $this->member($request);
        $purpose = isset($validated['purpose']) ? PaymentPurpose::from($validated['purpose']) : null;

        $plan = $purpose === PaymentPurpose::PlanPurchase && isset($validated['plan_id'])
            ? Plan::query()->publiclyListable()->findOrFail($validated['plan_id'])
            : null;

        $payable = $member !== null && $purpose !== null && isset($validated['payable_id'])
            ? $payables->find($purpose, (int) $validated['payable_id'], $member)
            : null;

        try {
            $result = $options->for($purpose, $member, $payable, $plan);
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

    /**
     * The member behind the bearer token, if any: only a member's app token
     * (the ability the member surface issues) counts, so a dashboard token
     * never reads as a wallet.
     */
    private function member(Request $request): ?User
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User || ! $user->hasRole('member') || ! $user->tokenCan('member-app')) {
            return null;
        }

        return $user;
    }
}

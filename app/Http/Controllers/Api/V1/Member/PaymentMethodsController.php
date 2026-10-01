<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Payments\MemberPayables;
use App\Domain\Finance\Payments\PaymentMethodOptions;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Controller;
use App\Support\Presenters\PriceLabelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET member/payment-methods — the payment methods the member may use, in
 * config/payments.php order, so a gateway added in config appears here with
 * no app change. The `wallet` method carries the member's points: `balance`
 * (the most they can spend from any wallet they may use) and `wallets`
 * (personal and company, each with its usable balance); with nothing to
 * spend it is unavailable with a reason.
 *
 * Every parameter is optional. `purpose` keeps only the methods that can pay
 * for it (a top-up: the card); with `payable_id` (the member's own record)
 * or `plan_id` it also prices it and says whether the wallet covers it.
 */
class PaymentMethodsController extends Controller
{
    public function index(
        Request $request,
        PaymentMethodOptions $options,
        MemberPayables $payables,
        PriceLabelPresenter $prices,
        PaymentAmountCalculator $amounts,
    ): JsonResponse {
        $validated = $request->validate([
            'purpose' => ['nullable', Rule::in(['wallet_top_up', 'plan_purchase', 'booking', 'coworking_session', 'event_registration'])],
            'payable_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $member = $request->user();
        $purpose = isset($validated['purpose']) ? PaymentPurpose::from($validated['purpose']) : null;

        $plan = $purpose === PaymentPurpose::PlanPurchase && isset($validated['plan_id'])
            ? Plan::query()->publiclyListable()->findOrFail($validated['plan_id'])
            : null;

        $payable = $purpose !== null && isset($validated['payable_id'])
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
                // A top-up has no price, only a floor: the fewest points
                // and what they cost in the charged currency.
                'min_top_up' => $purpose === PaymentPurpose::WalletTopUp ? $this->minimumTopUp($amounts) : null,
            ],
        ]);
    }

    /**
     * Null when the charged currency has no exchange rate yet: the
     * methods are still worth showing, and paying would 503 anyway.
     *
     * @return array{points: int, amount: string, currency: string}|null
     */
    private function minimumTopUp(PaymentAmountCalculator $amounts): ?array
    {
        try {
            $minimum = $amounts->minimumTopUp();
        } catch (PaymentPricingException) {
            return null;
        }

        return ['points' => $minimum['points'], 'amount' => $minimum['amount'], 'currency' => $minimum['currency']];
    }
}

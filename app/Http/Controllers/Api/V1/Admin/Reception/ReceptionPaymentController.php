<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\SettleAtReceptionRequest;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The reception desk's side of payments: settle anything a member owes
 * with the money handed over, and see everything they owe.
 */
class ReceptionPaymentController extends Controller
{
    use LogsSensitiveActions;

    /**
     * The money becomes points in the member's wallet, what is due is spent,
     * and anything over stays with them as points. Nothing is recorded when
     * it cannot complete (too little money, already paid, a full branch for
     * a plan).
     */
    public function settle(SettleAtReceptionRequest $request, PaymentService $payments): JsonResponse
    {
        $purpose = $request->purpose();

        try {
            $settled = $payments->settleAtReception(
                $purpose,
                $this->target($purpose, $request),
                $request->collectedVia(),
                $request->validated('amount') === null ? null : (string) $request->validated('amount'),
                $request->validated('currency'),
                $request->user(),
            );
        } catch (ReceptionActionException $e) {
            $body = ['message' => __($e->messageKey, $e->params)];

            // The shortage is what the desk asks the member for.
            if ($e->messageKey === 'api.payment.amount_short') {
                $body += $e->params;
            }

            return response()->json($body, $e->status);
        } catch (SeatPoolExhaustedException $e) {
            return response()->json([
                'message' => __('api.membership.seat_pool_full'),
                'first_shortfall_at' => $e->firstShortfall->toIso8601String(),
            ], 422);
        } catch (PaymentPricingException) {
            // A currency with no rate yet: the desk's to fix, not a gateway
            // outage.
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        }

        $this->logSensitiveAction('payment_settled_at_reception', $settled->payment, [
            'purpose' => $purpose->value,
            'collected_via' => $request->validated('collected_via'),
            'received_amount' => $settled->payment->received_amount,
            'received_currency' => $settled->payment->received_currency,
            'points_credited' => $settled->pointsCredited,
            'points_charged' => $settled->pointsCharged,
        ]);

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($settled->payment),
                'points_credited' => $settled->pointsCredited,
                'points_charged' => $settled->pointsCharged,
                'change_points' => $settled->changePoints(),
            ],
        ], 201);
    }

    /**
     * Everything a member owes, with totals in the base currency or the one
     * asked for.
     */
    public function outstanding(Request $request, OutstandingItems $items, CurrencyConversionService $currencies): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
        ]);

        try {
            $result = $items->for((int) $validated['user_id'], $validated['currency'] ?? $currencies->baseCurrencyCode());
        } catch (PaymentPricingException) {
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        }

        return response()->json(['data' => $result]);
    }

    private function target(PaymentPurpose $purpose, SettleAtReceptionRequest $request): Model|Payment
    {
        $id = (int) ($purpose === PaymentPurpose::PlanPurchase ? $request->validated('payment_id') : $request->validated('payable_id'));

        return match ($purpose) {
            PaymentPurpose::Booking => Booking::query()->findOrFail($id),
            PaymentPurpose::CoworkingSession => WalkinSession::query()->findOrFail($id),
            PaymentPurpose::EventRegistration => EventAttendee::query()->findOrFail($id),
            default => Payment::query()->findOrFail($id),
        };
    }
}

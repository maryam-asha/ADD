<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Payments\MemberPayables;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Finance\Services\StartPayment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Concerns\RespondsToGatewayFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StartPaymentRequest;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The member app's payment endpoints — by card, by wallet points, or at
 * the desk, for anything it pays for.
 *
 * The app never talks to Paymera itself — it asks for a URL here, opens
 * that URL in a WebView, and polls `show()` once the WebView returns. The
 * API key stays on the server, which is the whole point of the
 * arrangement.
 */
class PaymentController extends Controller
{
    use RespondsToGatewayFailures;

    /**
     * Opens a payment for anything the member pays for, by any enabled
     * method, and says what the app does next: `redirect` to the gateway's
     * page, `done` (paid on the spot, from the wallet), or
     * `pay_at_reception`.
     *
     * The request the app has always sent — no `method`, a top-up or a plan
     * — is still the card flow, answered with the same `payment` and
     * `redirect_url`, plus `next`.
     */
    public function store(
        StartPaymentRequest $request,
        StartPayment $card,
        PaymentService $payments,
        MemberPayables $payables,
    ): JsonResponse {
        $purpose = $request->purpose();
        $method = $request->paymentMethod();
        $member = $request->user();

        try {
            if ($method === PaymeraGateway::KEY && in_array($purpose, [PaymentPurpose::WalletTopUp, PaymentPurpose::PlanPurchase], true)) {
                ['payment' => $payment, 'url' => $url] = $card->handle(
                    user: $member,
                    purpose: $purpose,
                    plan: $purpose === PaymentPurpose::PlanPurchase ? Plan::findOrFail($request->validated('plan_id')) : null,
                    // Ignored for a plan purchase — the price comes from the
                    // database, never from the request.
                    amountMinor: $request->amountMinor(),
                    lang: $request->validated('lang'),
                );

                return $this->started($payment, PaymentStart::redirect($url));
            }

            ['payment' => $payment, 'start' => $start] = $payments->start($purpose, $member, $method, array_filter([
                'payable' => $payables->find($purpose, $request->validated('payable_id'), $member),
                'plan' => $purpose === PaymentPurpose::PlanPurchase ? Plan::findOrFail($request->validated('plan_id')) : null,
                // A plan paid from a wallet goes to that wallet's owner; from
                // this endpoint that is always the member.
                'wallet_owner_type' => $request->validated('wallet_owner_type') ?? ($purpose === PaymentPurpose::PlanPurchase ? OwnerType::User->value : null),
                'wallet_owner_id' => $request->validated('wallet_owner_id') ?? ($purpose === PaymentPurpose::PlanPurchase ? $member->id : null),
                'lang' => $request->validated('lang'),
            ], fn ($value) => $value !== null));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (WalletChoiceRequiredException $e) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        } catch (UnknownPaymentMethodException) {
            // The app's default method (a card) switched off in config.
            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        } catch (Throwable $e) {
            return $this->gatewayFailureResponse($e, $member->id) ?? throw $e;
        }

        return $this->started($payment, $start);
    }

    private function started(?Payment $payment, PaymentStart $start): JsonResponse
    {
        return response()->json([
            'data' => [
                'payment' => $payment === null ? null : new PaymentResource($payment),
                // What the app loads in its WebView, when there is one.
                'redirect_url' => $start->redirectUrl,
                'next' => $start->kind,
            ],
        ], 201);
    }

    /**
     * The status endpoint the app polls after its WebView closes.
     *
     * Syncs with the gateway on read rather than returning the stored row:
     * the app is usually asking precisely because the member just finished
     * paying, and the trigger may not have arrived yet. The sync is
     * idempotent and locked, so polling this cannot double-credit.
     */
    public function show(Request $request, Payment $payment, SyncPaymentStatus $sync): JsonResponse
    {
        // A member may only ever see their own payments. 404 rather than
        // 403 so this cannot be used to probe which payment ids exist.
        abort_unless($payment->user_id === $request->user()->id, 404);

        try {
            $payment = $sync->handle($payment);
        } catch (PaymeraException $e) {
            // The stored state is still worth returning — the reconciler
            // will settle it shortly.
            Log::channel('paymera')->warning('[Paymera] status poll failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['data' => new PaymentResource($payment)]);
    }

    /**
     * The member's own card payments, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
        ]);
    }
}

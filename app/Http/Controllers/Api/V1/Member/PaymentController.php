<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Exceptions\PaymeraUnauthorizedException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\StartPayment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StartPaymentRequest;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The member app's card-payment endpoints.
 *
 * The app never talks to Paymera itself — it asks for a URL here, opens
 * that URL in a WebView, and polls `show()` once the WebView returns. The
 * API key stays on the server, which is the whole point of the
 * arrangement.
 */
class PaymentController extends Controller
{
    /**
     * Opens a payment and returns the hosted page to load in a WebView.
     */
    public function store(StartPaymentRequest $request, StartPayment $start): JsonResponse
    {
        $purpose = $request->purpose();

        $plan = $purpose === PaymentPurpose::PlanPurchase
            ? Plan::findOrFail($request->validated('plan_id'))
            : null;

        try {
            ['payment' => $payment, 'url' => $url] = $start->handle(
                user: $request->user(),
                purpose: $purpose,
                plan: $plan,
                // Ignored for a plan purchase — the price comes from the
                // database, never from the request.
                amountMinor: $request->amountMinor(),
                lang: $request->validated('lang'),
            );
        } catch (PaymeraUnauthorizedException $e) {
            // A misconfigured key or an unregistered server IP. That is our
            // fault, not the member's, so it must not read as a declined
            // card — and the underlying detail must not reach the client.
            Log::channel('paymera')->critical('[Paymera] gateway credentials rejected', [
                'user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        } catch (PaymeraException $e) {
            Log::channel('paymera')->error('[Paymera] could not open payment', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('api.payment.could_not_start')], 502);
        }

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($payment),
                // What the app loads in its WebView.
                'redirect_url' => $url,
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

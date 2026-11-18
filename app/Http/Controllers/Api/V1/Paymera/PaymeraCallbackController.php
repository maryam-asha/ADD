<?php

namespace App\Http\Controllers\Api\V1\Paymera;

use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PaymentResource;
use App\Jobs\SyncPaymeraPaymentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * The two endpoints Paymera's hosted page points at.
 *
 * Both are unauthenticated by necessity — Paymera presents no credentials,
 * and the member returns from an external page with no session in the
 * WebView. What protects them is that the URLs carry a 64-character random
 * token rather than a payment id, so they cannot be enumerated, plus rate
 * limiting on the routes themselves.
 *
 * Neither endpoint is told the outcome, and neither decides one. The
 * trigger call carries no result at all — it only says the hosted page has
 * finished — and a member reaches the callback just as readily by pressing
 * Cancel as by paying. Both therefore do the same thing: ask
 * SyncPaymentStatus to go and find out from get-payment-status, which is
 * the only trustworthy source.
 */
class PaymeraCallbackController extends Controller
{
    /**
     * Where the member lands after Finish or Cancel.
     *
     * Syncs inline rather than queueing, because the member is waiting and
     * the app polls immediately afterwards — a queued check would usually
     * show them a stale "pending".
     */
    public function callback(string $token, SyncPaymentStatus $sync): JsonResponse
    {
        $payment = $this->resolve($token);

        try {
            $payment = $sync->handle($payment);
        } catch (PaymeraException $e) {
            // The member still gets a coherent answer; the reconciler will
            // settle the payment shortly.
            Log::channel('paymera')->warning('[Paymera] callback sync failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['data' => new PaymentResource($payment)]);
    }

    /**
     * Paymera's own notification that the hosted page has finished.
     *
     * Answers 200 immediately and does the work on the queue. The gateway
     * is waiting on this response, and a slow status call here would hold
     * its result page open; worse, a failure would look to Paymera like a
     * broken merchant endpoint. Registered for both GET and POST because
     * the spec does not pin the verb down.
     */
    public function trigger(string $token): JsonResponse
    {
        $payment = $this->resolve($token);

        SyncPaymeraPaymentJob::dispatch($payment->id);

        Log::channel('paymera')->info('[Paymera] trigger received', [
            'payment_id' => $payment->id,
        ]);

        // Deliberately says nothing about the payment: this endpoint is
        // unauthenticated, and its response should not confirm amounts or
        // outcomes to whoever called it.
        return response()->json(['message' => 'ok']);
    }

    /**
     * 404 for an unknown token — never a message distinguishing "no such
     * payment" from anything else.
     */
    private function resolve(string $token): Payment
    {
        return Payment::where('callback_token', $token)->firstOrFail();
    }
}

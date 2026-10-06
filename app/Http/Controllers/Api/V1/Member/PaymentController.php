<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The member app's payment reads: their payments, and one payment's status.
 *
 * Nothing is paid here. Each operation opens its own payment — a booking or
 * an event registration when its request is sent again with its id, a
 * co-working session through its `pay` endpoint, a top-up through
 * `wallet/top-ups`, a plan through `memberships`. When the answer sends the
 * app to a gateway page it opens that URL in a WebView and polls `show()`
 * once the WebView returns; the API key stays on the server, which is the
 * whole point of the arrangement.
 */
class PaymentController extends Controller
{
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

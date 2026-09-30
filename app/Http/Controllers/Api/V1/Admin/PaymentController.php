<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Services\ReversePayment;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Operations' view of card payments, and the reversal button.
 *
 * Deliberately does not extend AdminResourceController: that base returns
 * every row unpaginated, which suits small bounded tables and not a
 * payments ledger — the same reasoning as ErrorLogController. There is
 * also no store/update/destroy here at all. A payment is created by a
 * member paying and settled by the gateway; the only thing an operator may
 * do to one is reverse it.
 */
class PaymentController extends Controller
{
    /**
     * Filterable, paginated. `needs_review` is the filter operations
     * actually lives in — those are the payments with money on the line.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'method' => ['nullable', 'string', 'max:20'],
            'purpose' => ['nullable', Rule::enum(PaymentPurpose::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($validated['method'] ?? null, fn ($q, $method) => $q->where('method', $method))
            ->when($validated['purpose'] ?? null, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->with(['user', 'plan'])
            ->latest()
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json([
            'data' => new PaymentResource($payment->load(['user', 'plan', 'walletTransaction', 'reversedBy'])),
        ]);
    }

    /**
     * Reverses an accepted payment at the bank.
     *
     * Gated by `permission:payments.reverse` on the route, not by role —
     * following the Branches pilot (docs/decisions/rbac-permission-pilot.md).
     * Returns a message rather than the resource, per this repo's rule that
     * update-style endpoints do not echo the model back.
     */
    public function reverse(Request $request, Payment $payment, ReversePayment $reversals): JsonResponse
    {
        if (! $payment->status->isReversible()) {
            return response()->json([
                'message' => __('api.payment.not_reversible'),
            ], 422);
        }

        try {
            $reversals->handle($payment, $request->user());
        } catch (PaymeraException $e) {
            Log::channel('paymera')->error('[Paymera] reversal rejected by gateway', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('api.payment.reversal_failed')], 502);
        } catch (UnknownPaymentMethodException $e) {
            // Paid by wallet points or cash (or by a method since removed
            // from config): there is no gateway to reverse it through.
            // Refused the same way as any other non-reversible payment.
            return response()->json(['message' => __('api.payment.not_reversible')], 422);
        } catch (\RuntimeException $e) {
            // Raced with another reversal or a status sync.
            return response()->json(['message' => __('api.payment.not_reversible')], 422);
        }

        return response()->json(['message' => __('api.payment.reversed')]);
    }
}

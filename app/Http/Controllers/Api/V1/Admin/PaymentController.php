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
use App\Support\PlainAmount;
use App\Support\Time\LocalTime;
use Illuminate\Database\Eloquent\Builder;
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
        $validated = $this->validatedFilters($request);

        $payments = $this->filtered($validated)
            ->with(['user', 'plan', 'receivedBy:id,name'])
            ->latest()
            ->latest('id')
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

    /**
     * The totals over the same filters as `index`: how many payments in each
     * status, what was accepted per method in points, and the money behind
     * it — cash handed over at the desk and what cards were charged — per
     * currency, as received.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $this->validatedFilters($request);
        $accepted = PaymentStatus::Accepted->value;

        $byStatus = $this->filtered($validated)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $byMethod = $this->filtered($validated)
            ->selectRaw('method, COUNT(*) as payments_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as accepted_count', [$accepted])
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(amount_points, points_to_credit, 0) ELSE 0 END) as accepted_points', [$accepted])
            ->groupBy('method')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'method' => $row->method,
                'label' => __("api.payment.methods.{$row->method}"),
                'count' => (int) $row->payments_count,
                'accepted_count' => (int) $row->accepted_count,
                'accepted_points' => (int) $row->accepted_points,
            ])
            ->sortByDesc('accepted_points')
            ->values();

        $cash = $this->filtered($validated)
            ->where('status', $accepted)
            ->whereNotNull('received_amount')
            ->selectRaw('received_currency as currency, SUM(received_amount) as amount')
            ->groupBy('received_currency')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['currency' => $row->currency, 'amount' => PlainAmount::of($row->amount)])
            ->values();

        $card = $this->filtered($validated)
            ->where('status', $accepted)
            ->whereNotNull('charged_amount')
            ->selectRaw('currency, SUM(charged_amount) as amount')
            ->groupBy('currency')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['currency' => $row->currency, 'amount' => PlainAmount::of($row->amount)])
            ->values();

        return response()->json(['data' => [
            'total' => $byStatus->sum(),
            'by_status' => (object) $byStatus->all(),
            'accepted' => [
                'count' => (int) ($byStatus[$accepted] ?? 0),
                'points' => (int) $byMethod->sum('accepted_points'),
            ],
            'by_method' => $byMethod,
            'cash' => $cash,
            'card' => $card,
        ]]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json([
            'data' => new PaymentResource($payment->load(['user', 'plan', 'walletTransaction', 'reversedBy', 'receivedBy:id,name'])),
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

    /**
     * The filters `index` and `summary` share. `from`/`to` are district
     * calendar days, both inclusive; `search` finds a member by name or
     * phone, or a payment by its number or the bank's reference.
     *
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'method' => ['nullable', 'string', 'max:20'],
            'purpose' => ['nullable', Rule::enum(PaymentPurpose::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    private function filtered(array $filters): Builder
    {
        $time = app(LocalTime::class);
        $search = trim((string) ($filters['search'] ?? ''));

        return Payment::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['method'] ?? null, fn ($q, $method) => $q->where('method', $method))
            ->when($filters['purpose'] ?? null, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $time->toUtc($from.' 00:00')))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', $time->toUtc($to.' 00:00')->addDay()))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->orWhere('rrn', $search);

                    $number = ltrim($search, '#');

                    if ($number !== '' && ctype_digit($number)) {
                        $q->orWhere('id', (int) $number);
                    }
                });
            });
    }
}

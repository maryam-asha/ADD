<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Events\CoworkingPaymentAtReception;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\ArrivalRequestService;
use App\Domain\Booking\Services\CheckoutTokenService;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\SessionEndService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Finance\Payments\PaymentStart;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ListCoworkingSessionsRequest;
use App\Http\Requests\Member\PayCoworkingSessionRequest;
use App\Http\Requests\Member\RequestSessionEndRequest;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\CurrentCoworkingSessionResource;
use App\Http\Resources\MemberCoworkingSessionResource;
use App\Http\Resources\SessionEndRequestResource;
use App\Services\Realtime\RealtimeBroadcaster;
use App\Support\Presenters\PriceLabelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** docs/decisions/coworking-self-service-sessions.md */
class CoworkingSessionController extends Controller
{
    use AnswersPayments;
    use LogsSensitiveActions;

    /**
     * The running session (state active), or the scan still waiting on
     * reception to start one (state awaiting_confirmation) — the same keys
     * either way (CurrentCoworkingSessionResource) — or null.
     */
    public function current(Request $request, CoworkingSessionService $sessions, ArrivalRequestService $arrivals): JsonResponse
    {
        $current = $sessions->openSessionFor($request->user()) ?? $arrivals->pendingFor($request->user());

        if ($current instanceof ArrivalRequest && ! $current->isCoworkingStart()) {
            $current = null;
        }

        return response()->json([
            'data' => $current === null ? null : (new CurrentCoworkingSessionResource($current))->resolve($request),
        ]);
    }

    /**
     * What ending the running session now would come to, before the member
     * asks to end it: the report so far and what closing now would charge
     * (the same quote reception closes from). How to pay is chosen later,
     * once reception has confirmed the end (pay()).
     *
     * What this shows is final: `checkout_token` seals the time and the price,
     * the member sends it with their end request, and reception closes the
     * session at exactly that time — however long the member takes to press,
     * whatever reception does. Past `checkout_token_expires_at` the app shows
     * a fresh checkout.
     */
    public function checkout(
        Request $request,
        CoworkingSessionService $sessions,
        SessionReportBuilder $reports,
        SessionClosureService $closures,
        CheckoutTokenService $checkouts,
        PriceLabelPresenter $prices,
    ): JsonResponse {
        $member = $request->user();
        $session = $sessions->openSessionFor($member);

        if ($session === null) {
            return response()->json(['message' => __('api.coworking.no_open_session')], 422);
        }

        // One instant for the duration, the price and the token alike.
        $at = now()->startOfSecond();
        $quote = $closures->quote($session, $at);
        $points = $quote['already_paid'] ? 0 : $quote['points'];
        $labels = $prices->total($points, $member);
        $token = $checkouts->issue($session, $at, $points);

        return response()->json(['data' => [
            ...$reports->build($session),
            'closing' => [
                'at' => $at->toIso8601String(),
                'points' => $points,
                'price' => $labels['price'],
                'price_numeric' => $labels['price_numeric'],
                'currency' => $labels['currency'],
                'price_point' => $labels['price_point'],
                'covered_seconds' => $quote['covered_seconds'],
                // A booking paid when it was made owes nothing more.
                'already_paid' => $quote['already_paid'],
            ],
            'checkout_token' => $token->token,
            'checkout_token_expires_at' => $token->expiresAt->toIso8601String(),
        ]]);
    }

    /**
     * The member's past sessions, newest first — walk-ins and checked-in
     * bookings on a coworking space together — as cards
     * (MemberCoworkingSessionResource). The running one is current().
     */
    public function index(ListCoworkingSessionsRequest $request, CoworkingSessionService $sessions): JsonResponse
    {
        $paymentState = $request->paymentState();
        $paginator = $sessions->pastSessionsFor($request->user(), $paymentState, (int) ($request->validated('per_page') ?? 15));

        return response()->json([
            'data' => MemberCoworkingSessionResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                // What was filtered on; null for every session.
                'payment_state' => $paymentState?->value,
            ],
        ]);
    }

    /**
     * One session in full: its card, the session itself (coverage, how it
     * started and ended), the report's lines, plan and totals, and every
     * attempt to pay for it. Not the member's: 404, as for the report.
     */
    public function show(Request $request, string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        $report = $reports->build($session);

        return response()->json(['data' => (new MemberCoworkingSessionResource($session))->resolve($request)
            + (new CoworkingSessionResource($session))->resolve($request)
            + [
                'termination_source' => $report['session']['termination_source'],
                'lines' => $report['lines'],
                'plan' => $report['plan'],
                'totals' => $report['totals'],
                'payments' => $this->paymentAttempts($session),
            ]]);
    }

    /** A session that is not the caller's is a 404, not a 403 — its existence is not theirs to learn. */
    public function report(Request $request, string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        return response()->json(['data' => [
            ...$reports->build($session),
            'payments' => $this->paymentAttempts($session),
        ]]);
    }

    /**
     * A closed session is paid as the member chooses once reception has
     * confirmed the end, from the methods GET member/payment-methods lists
     * for purpose coworking_session (or booking): `wallet` spends the points
     * now, a gateway (`paymera`) answers with its `redirect_url`, and `cash`
     * answers `next: pay_at_reception` and tells the desk to collect it
     * (CoworkingPaymentAtReception), which settles it through
     * settle-payment. There is no create request to send again for a
     * session, so it has its own endpoint. A booking left without a method
     * is paid by the one it was booked with. Not the member's: 404, as for
     * the report.
     */
    public function pay(PayCoworkingSessionRequest $request, string $type, int $id, CoworkingSessionService $sessions, PayableCheckout $checkout, RealtimeBroadcaster $realtime): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        $method = $request->validated('payment_method') ?? ($session instanceof Booking ? $session->chosen_payment_method : null);

        if ($method === null) {
            return response()->json(['message' => __('api.booking.payment_method_required')], 422);
        }

        try {
            $outcome = $checkout->pay(
                $session instanceof Booking ? PaymentPurpose::Booking : PaymentPurpose::CoworkingSession,
                $session,
                $request->user(),
                $method,
                [
                    'wallet_owner_type' => $request->validated('wallet_owner_type'),
                    'wallet_owner_id' => $request->validated('wallet_owner_id'),
                ],
                $request->validated('coupon_code'),
            );
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $request->user()->id) ?? throw $e;
        }

        if ($outcome->start->kind === PaymentStart::PAY_AT_RECEPTION) {
            $realtime->send(new CoworkingPaymentAtReception($session));
        }

        return response()->json(
            ['data' => $this->checkoutFields($outcome)] + ($outcome->coupon !== null ? ['coupon' => $outcome->coupon] : []),
            201,
        );
    }

    public function requestEnd(RequestSessionEndRequest $request, SessionEndService $ends): JsonResponse
    {
        try {
            $endRequest = $ends->request($request->user(), $request->validated('checkout_token'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        if ($endRequest->wasRecentlyCreated) {
            $this->logSensitiveAction('session_end_requested', $endRequest);
        }

        return response()->json(['data' => new SessionEndRequestResource($endRequest)], $endRequest->wasRecentlyCreated ? 201 : 200);
    }

    public function cancelEnd(Request $request, SessionEndService $ends): JsonResponse
    {
        try {
            $ends->cancel($request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.coworking.end_request_cancelled')]);
    }

    /**
     * Every attempt to pay for a session, oldest first, as it stands: a card
     * that failed or was abandoned stays here beside the one that went
     * through.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentAttempts(Booking|WalkinSession $session): array
    {
        return Payment::query()->forPayable($session)->oldest('id')->get()->map(fn (Payment $payment) => [
            'id' => $payment->id,
            'method' => $payment->method,
            'status' => $payment->status?->value,
            'amount_points' => $payment->amount_points,
            'created_at' => $payment->created_at?->toIso8601String(),
            'settled_at' => $payment->settled_at?->toIso8601String(),
        ])->values()->all();
    }
}

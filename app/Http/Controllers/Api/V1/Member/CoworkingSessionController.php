<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\ArrivalRequestService;
use App\Domain\Booking\Services\CheckoutTokenService;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\SessionEndService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\PayCoworkingSessionRequest;
use App\Http\Requests\Member\RequestSessionEndRequest;
use App\Http\Resources\ArrivalRequestResource;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\SessionEndRequestResource;
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
     * reception to start one (state awaiting_confirmation), or null.
     */
    public function current(Request $request, CoworkingSessionService $sessions, ArrivalRequestService $arrivals): JsonResponse
    {
        $open = $sessions->openSessionFor($request->user());

        if ($open !== null) {
            return response()->json(['data' => ['state' => 'active'] + (new CoworkingSessionResource($open))->resolve($request)]);
        }

        $pending = $arrivals->pendingFor($request->user());

        return response()->json(['data' => $pending !== null && $pending->isCoworkingStart() ? [
            'state' => 'awaiting_confirmation',
            'arrival_request' => (new ArrivalRequestResource($pending))->resolve($request),
        ] : null]);
    }

    /**
     * What ending the running session now would come to, before the member
     * asks to end it, so they choose how to pay knowing the amount: the
     * report so far and what closing now would charge (the same quote
     * reception closes from). The methods come from GET member/payment-methods
     * with purpose coworking_session (or booking).
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

    /** A session that is not the caller's is a 404, not a 403 — its existence is not theirs to learn. */
    public function report(Request $request, string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        return response()->json(['data' => [
            ...$reports->build($session),
            // Every attempt to pay for it, oldest first, as it stands: a card
            // that failed or was abandoned stays here beside the one that went
            // through.
            'payments' => Payment::query()->forPayable($session)->oldest('id')->get()->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'method' => $payment->method,
                'status' => $payment->status?->value,
                'amount_points' => $payment->amount_points,
                'created_at' => $payment->created_at?->toIso8601String(),
                'settled_at' => $payment->settled_at?->toIso8601String(),
            ])->values()->all(),
        ]]);
    }

    /**
     * A closed session is paid as the member asks — the card chosen when
     * ending it, the wallet, or at the desk. There is no create request to
     * send again for a session, so it has its own endpoint. Not the
     * member's: 404, as for the report.
     */
    public function pay(PayCoworkingSessionRequest $request, string $type, int $id, CoworkingSessionService $sessions, PayableCheckout $checkout): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        try {
            $outcome = $checkout->pay(
                $session instanceof Booking ? PaymentPurpose::Booking : PaymentPurpose::CoworkingSession,
                $session,
                $request->user(),
                $request->validated('payment_method'),
                [
                    'wallet_owner_type' => $request->validated('wallet_owner_type'),
                    'wallet_owner_id' => $request->validated('wallet_owner_id'),
                ],
                $request->validated('coupon_code'),
            );
        } catch (Throwable $e) {
            return $this->paymentFailureResponse($e, $request->user()->id) ?? throw $e;
        }

        return response()->json(
            ['data' => $this->checkoutFields($outcome)] + ($outcome->coupon !== null ? ['coupon' => $outcome->coupon] : []),
            201,
        );
    }

    public function requestEnd(RequestSessionEndRequest $request, SessionEndService $ends): JsonResponse
    {
        try {
            $endRequest = $ends->request($request->user(), $request->validated('payment_method'), $request->validated('checkout_token'));
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
}

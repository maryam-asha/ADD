<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\SessionEndService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\PayCoworkingSessionRequest;
use App\Http\Requests\Member\RequestSessionEndRequest;
use App\Http\Requests\Member\StartCoworkingSessionRequest;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\SessionCoverageResource;
use App\Http\Resources\SessionEndRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** docs/decisions/coworking-self-service-sessions.md */
class CoworkingSessionController extends Controller
{
    use AnswersPayments;
    use LogsSensitiveActions;

    public function start(StartCoworkingSessionRequest $request, CoworkingSessionService $sessions): JsonResponse
    {
        try {
            $result = $sessions->start(
                $request->user(),
                $request->validated('qr_value'),
                $request->validated('scan_ticket'),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        if ($result->outcome === CoworkingStartOutcome::ConfirmationRequired) {
            // Same message-plus-data 422 shape as api.booking.wallet_choice_required:
            // the client must answer, then re-submit.
            return response()->json([
                'message' => __('api.coworking.confirmation_required', [
                    'reason' => __('api.coworking.reasons.'.$result->coverage->reason->value),
                    'rate' => $result->hourlyRatePoints,
                ]),
                'reason' => $result->coverage->reason->value,
                'coverage' => new SessionCoverageResource($result->coverage->toArray()),
                'hourly_rate_points' => $result->hourlyRatePoints,
                'scan_ticket' => $result->ticket->ticket,
                'ticket_expires_at' => $result->ticket->expiresAt->toIso8601String(),
            ], 422);
        }

        if ($result->outcome === CoworkingStartOutcome::Existing) {
            return response()->json(['data' => new CoworkingSessionResource($result->session)]);
        }

        $this->logSensitiveAction('coworking_session_started', $result->session, ['via' => 'scan']);

        return response()->json(['data' => new CoworkingSessionResource($result->session)], 201);
    }

    public function current(Request $request, CoworkingSessionService $sessions): JsonResponse
    {
        $open = $sessions->openSessionFor($request->user());

        return response()->json(['data' => $open === null ? null : new CoworkingSessionResource($open)]);
    }

    /** A session that is not the caller's is a 404, not a 403 — its existence is not theirs to learn. */
    public function report(Request $request, string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        return response()->json(['data' => $reports->build($session)]);
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
            $endRequest = $ends->request($request->user(), $request->validated('payment_method'));
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

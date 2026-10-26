<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\ArrivalRequestService;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\SessionEndService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\PayCoworkingSessionRequest;
use App\Http\Requests\Member\RequestSessionEndRequest;
use App\Http\Resources\ArrivalRequestResource;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\SessionEndRequestResource;
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

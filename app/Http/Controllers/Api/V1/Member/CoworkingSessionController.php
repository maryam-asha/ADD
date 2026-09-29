<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StartCoworkingSessionRequest;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\SessionCoverageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** docs/decisions/coworking-self-service-sessions.md */
class CoworkingSessionController extends Controller
{
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
}

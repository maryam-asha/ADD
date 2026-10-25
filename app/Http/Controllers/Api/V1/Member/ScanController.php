<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Services\UnlockService;
use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ScanRequest;
use App\Http\Resources\ArrivalRequestResource;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\SessionCoverageResource;
use Illuminate\Http\JsonResponse;

/**
 * The app's one scan button. The value says what was scanned: the kiosk's
 * rotating code (`add-cowork:v1:…`) starts a coworking session, checks a
 * booking in or tells reception the member is here
 * (docs/decisions/coworking-self-service-sessions.md); anything else is
 * looked up as a lock's sticker and opens that door
 * (docs/decisions/qr-lock-unlock.md). Every answer that succeeds says which
 * in data.outcome.
 */
class ScanController extends Controller
{
    use LogsSensitiveActions;

    public function scan(ScanRequest $request, CoworkingSessionService $sessions, UnlockService $locks): JsonResponse
    {
        $qrValue = $request->validated('qr_value');

        // A ticket only ever comes from a kiosk scan's confirmation_required.
        if ($qrValue === null || str_starts_with(trim($qrValue), Kiosk::QR_PREFIX.':')) {
            return $this->coworking($request, $sessions);
        }

        return $this->unlock($request, $locks, $qrValue);
    }

    private function coworking(ScanRequest $request, CoworkingSessionService $sessions): JsonResponse
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

        if ($result->outcome === CoworkingStartOutcome::ArrivalRequested) {
            return response()->json(['data' => [
                'outcome' => $result->outcome->value,
                'arrival_request' => (new ArrivalRequestResource($result->arrivalRequest))->resolve($request),
                'booking' => [
                    'id' => $result->booking->id,
                    'space' => [
                        'id' => $result->booking->space->id,
                        'name' => $result->booking->space->translate('name'),
                        'space_type' => $result->booking->space->space_type,
                    ],
                    'start_at' => $result->booking->start_at->toIso8601String(),
                    'end_at' => $result->booking->end_at->toIso8601String(),
                ],
            ]], $result->arrivalRequest->wasRecentlyCreated ? 201 : 200);
        }

        $this->logSensitiveAction('coworking_session_started', $result->session, ['via' => 'scan']);

        return response()->json([
            'data' => ['outcome' => 'session'] + (new CoworkingSessionResource($result->session))->resolve($request),
        ], 201);
    }

    /** Nothing but the outcome: no lock or vendor detail reaches the member (LockCredentialsNeverReachMemberTest). */
    private function unlock(ScanRequest $request, UnlockService $locks, string $qrValue): JsonResponse
    {
        try {
            $locks->unlock($request->user(), $qrValue);
        } catch (LockAccessDeniedException $e) {
            // Not a lock either: the member scanned something that is not ours.
            if ($e->messageKey === 'api.access.lock_not_found') {
                return response()->json(['message' => __('api.scan.unknown_code')], 422);
            }

            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['data' => [
            'outcome' => 'unlocked',
            'message' => __('api.access.unlocked'),
        ]]);
    }
}

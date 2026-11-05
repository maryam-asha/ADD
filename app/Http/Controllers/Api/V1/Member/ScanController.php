<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Services\UnlockService;
use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ScanRequest;
use App\Http\Resources\ArrivalRequestResource;
use App\Http\Resources\SessionCoverageResource;
use Illuminate\Http\JsonResponse;

/**
 * The app's one scan button. The value says what was scanned: the kiosk's
 * rotating code (`add-cowork:v1:…`) files a request reception confirms —
 * the coworking session or the coworking booking's check-in starts at that
 * confirmation, whatever room the member has booked later
 * (docs/decisions/coworking-self-service-sessions.md); anything else is
 * looked up as a lock's sticker and opens that door
 * (docs/decisions/qr-lock-unlock.md). Every answer that succeeds says which
 * in data.outcome.
 */
class ScanController extends Controller
{
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
            // A question for the member, not an error: 200 with its own outcome so
            // the app switches on data.outcome like every other answer, then
            // re-submits {scan_ticket, accept_uncovered: true} if they accept.
            return response()->json(['data' => [
                'outcome' => $result->outcome->value,
                'message' => __('api.coworking.confirmation_required', [
                    'reason' => __('api.coworking.reasons.'.$result->coverage->reason->value),
                    'rate' => $result->hourlyRatePoints,
                ]),
                'reason' => $result->coverage->reason->value,
                'coverage' => (new SessionCoverageResource($result->coverage->toArray()))->resolve($request),
                'hourly_rate_points' => $result->hourlyRatePoints,
                'scan_ticket' => $result->ticket->ticket,
                'ticket_expires_at' => $result->ticket->expiresAt->toIso8601String(),
            ]]);
        }

        // A request for reception, either kind: nothing has started yet.
        $booking = $result->booking ?? $result->arrivalRequest->matchedBooking;

        return response()->json(['data' => [
            'outcome' => $result->outcome->value,
            'arrival_request' => (new ArrivalRequestResource($result->arrivalRequest))->resolve($request),
            'booking' => $booking === null ? null : [
                'id' => $booking->id,
                'space' => [
                    'id' => $booking->space->id,
                    'name' => $booking->space->translate('name'),
                    'space_type' => $booking->space->space_type,
                ],
                'start_at' => $booking->start_at->toIso8601String(),
                'end_at' => $booking->end_at->toIso8601String(),
            ],
        ]], $result->arrivalRequest->wasRecentlyCreated ? 201 : 200);
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

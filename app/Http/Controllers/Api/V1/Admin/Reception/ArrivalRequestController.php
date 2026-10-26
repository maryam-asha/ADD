<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Events\ArrivalRequestResolved;
use App\Domain\Booking\Events\CoworkingStartRejected;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Booking\Services\CoworkingStartConfirmation;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalkInSessionRequest;
use App\Http\Resources\ArrivalRequestResource;
use App\Services\Notification\NotificationService;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Redirector;

class ArrivalRequestController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        $requests = ArrivalRequest::query()
            ->where('status', ArrivalRequestStatus::Pending)
            ->orderBy('requested_at')
            ->with(['user.personalProfile', 'space', 'matchedBooking.space', 'handoverBooking'])
            ->paginate(25);

        return ArrivalRequestResource::collection($requests);
    }

    public function confirm(
        Request $request,
        ArrivalRequest $arrivalRequest,
        BookingCheckInService $checkIns,
        CoworkingStartConfirmation $coworking,
        NotificationService $notifications,
        RealtimeBroadcaster $realtime,
    ): JsonResponse {
        if ($arrivalRequest->status !== ArrivalRequestStatus::Pending) {
            return response()->json(['message' => __('api.kiosk.arrival_request_not_pending')], 409);
        }

        // A coworking session the member scanned for at a kiosk: it starts
        // now. The space is the kiosk's, so no space_id is asked for.
        if ($arrivalRequest->isCoworkingStart()) {
            try {
                $session = $coworking->confirm($arrivalRequest, $request->user());
            } catch (ReceptionActionException $e) {
                return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
            }

            $this->logSensitiveAction('coworking_session_started', $session, ['via' => 'scan', 'arrival_request_id' => $arrivalRequest->id]);
            $this->logSensitiveAction('arrival_request_confirmed', $arrivalRequest->fresh());

            $notifications->sendToUser(
                $arrivalRequest->user,
                'coworking.session_started',
                ['space' => $session->space->translate('name')],
                ['session_type' => $session->coworkingType(), 'session_id' => (string) $session->id],
            );

            return response()->json(['message' => __('api.kiosk.arrival_request_confirmed')]);
        }

        if ($arrivalRequest->matched_booking_id !== null) {
            try {
                $checkIns->checkIn($arrivalRequest->matchedBooking);
            } catch (ReceptionActionException $e) {
                return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
            }

            $this->logSensitiveAction('booking_checked_in', $arrivalRequest->matchedBooking);

            $confirmedSpaceId = $arrivalRequest->matchedBooking->space_id;
        } else {
            if (! $request->filled('space_id')) {
                return response()->json(['message' => __('api.kiosk.space_id_required')], 422);
            }

            $walkInRequest = StoreWalkInSessionRequest::create('/', 'POST', [
                'space_id' => $request->input('space_id'),
                'user_id' => $arrivalRequest->user_id,
            ]);
            $walkInRequest->setContainer(app());
            $walkInRequest->setRedirector(app(Redirector::class));
            $walkInRequest->validateResolved();

            $response = app(WalkInSessionController::class)->store($walkInRequest, app(WalkInCapacityService::class));

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            $confirmedSpaceId = (int) $request->input('space_id');
        }

        $arrivalRequest->forceFill([
            'status' => ArrivalRequestStatus::Confirmed,
            'confirmed_by_user_id' => $request->user()->id,
            'confirmed_space_id' => $confirmedSpaceId,
        ])->save();

        $this->logSensitiveAction('arrival_request_confirmed', $arrivalRequest);

        // A coworking start announces itself (CoworkingStartConfirmation);
        // this is the room/office/hall arrival, which only the desk follows.
        $realtime->send(new ArrivalRequestResolved($arrivalRequest, ArrivalRequestStatus::Confirmed));

        return response()->json(['message' => __('api.kiosk.arrival_request_confirmed')]);
    }

    public function reject(ArrivalRequest $arrivalRequest, NotificationService $notifications, RealtimeBroadcaster $realtime): JsonResponse
    {
        if ($arrivalRequest->status !== ArrivalRequestStatus::Pending) {
            return response()->json(['message' => __('api.kiosk.arrival_request_not_pending')], 409);
        }

        $arrivalRequest->forceFill(['status' => ArrivalRequestStatus::Rejected])->save();

        $this->logSensitiveAction('arrival_request_rejected', $arrivalRequest);

        // The member is waiting in the app for a session that will not start.
        if ($arrivalRequest->isCoworkingStart()) {
            $notifications->sendToUser($arrivalRequest->user, 'coworking.start_rejected');
            $realtime->send(new CoworkingStartRejected($arrivalRequest));
        }

        $realtime->send(new ArrivalRequestResolved($arrivalRequest, ArrivalRequestStatus::Rejected));

        return response()->json(['message' => __('api.kiosk.arrival_request_rejected')]);
    }
}

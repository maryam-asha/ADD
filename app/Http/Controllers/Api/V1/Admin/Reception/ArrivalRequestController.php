<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalkInSessionRequest;
use App\Http\Resources\ArrivalRequestResource;
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
            ->with(['user', 'matchedBooking.space'])
            ->paginate(25);

        return ArrivalRequestResource::collection($requests);
    }

    public function confirm(Request $request, ArrivalRequest $arrivalRequest): JsonResponse
    {
        if ($arrivalRequest->status !== ArrivalRequestStatus::Pending) {
            return response()->json(['message' => __('api.kiosk.arrival_request_not_pending')], 409);
        }

        if ($arrivalRequest->matched_booking_id !== null) {
            $response = app(BookingReceptionController::class)->checkIn(
                $arrivalRequest->matchedBooking,
                app(BusinessHoursService::class)
            );

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

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

        return response()->json(['message' => __('api.kiosk.arrival_request_confirmed')]);
    }

    public function reject(ArrivalRequest $arrivalRequest): JsonResponse
    {
        if ($arrivalRequest->status !== ArrivalRequestStatus::Pending) {
            return response()->json(['message' => __('api.kiosk.arrival_request_not_pending')], 409);
        }

        $arrivalRequest->forceFill(['status' => ArrivalRequestStatus::Rejected])->save();

        $this->logSensitiveAction('arrival_request_rejected', $arrivalRequest);

        return response()->json(['message' => __('api.kiosk.arrival_request_rejected')]);
    }
}

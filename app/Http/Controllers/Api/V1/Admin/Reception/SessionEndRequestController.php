<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Services\SessionEndService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\ConfirmSessionEndRequest;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class SessionEndRequestController extends Controller
{
    use LogsSensitiveActions;

    public function confirm(
        ConfirmSessionEndRequest $request,
        SessionEndRequest $sessionEndRequest,
        SessionEndService $ends,
        NotificationService $notifications,
    ): JsonResponse {
        $override = $request->validated('checked_out_at');

        try {
            $ends->confirm($sessionEndRequest, $request->user(), $override === null ? null : Carbon::parse($override, 'UTC'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $session = $sessionEndRequest->sessionable;

        // fresh(): the relation above was loaded before the closure, so it
        // still holds the unbilled row. Same amount the desk checkouts audit.
        $this->logSensitiveAction('session_end_confirmed', $session, [
            'end_request_id' => $sessionEndRequest->id,
            'amount_owed' => (string) $session->fresh()->amount_owed,
        ]);

        $notifications->sendToUser(
            $session->user,
            'coworking.session_ended',
            ['space' => $session->space->translate('name')],
            ['session_type' => $session->coworkingType(), 'session_id' => (string) $session->id],
        );

        return response()->json(['message' => __('api.coworking.session_ended')]);
    }
}

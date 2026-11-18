<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\GuestVisit;
use App\Domain\Booking\Services\GuestVisitService;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreGuestVisitRequest;
use App\Http\Resources\GuestVisitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Coworking guests at the desk (GuestVisitService): the guests inside on a
 * visit, recording one on arrival, and checking one out.
 */
class GuestVisitController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return GuestVisitResource::collection(
            GuestVisit::query()->active()->with('host')->oldest('grace_ends_at')->get(),
        );
    }

    public function store(StoreGuestVisitRequest $request, GuestVisitService $visits): JsonResponse
    {
        $host = User::findOrFail($request->validated('host_user_id'));

        try {
            $visit = $visits->register($host, $request->validated('phone'), $request->validated('name'), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('guest_visit_registered', $visit);

        return response()->json(['data' => (new GuestVisitResource($visit->load('host')))->resolve($request)], 201);
    }

    public function checkOut(GuestVisit $guestVisit, GuestVisitService $visits): JsonResponse
    {
        try {
            $session = $visits->checkOut($guestVisit);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('guest_visit_checked_out', $guestVisit, ['walkin_session_id' => $session?->id]);

        return response()->json(['message' => __($session === null ? 'api.guest.checked_out' : 'api.guest.checked_out_billed')]);
    }
}

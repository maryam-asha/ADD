<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\GuestVisit;
use App\Domain\Booking\Services\GuestVisitService;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuestVisitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The host's side of their coworking guests: who is inside on a visit, and
 * refusing to pay for one with no account of their own while the grace runs.
 */
class GuestVisitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return GuestVisitResource::collection(
            GuestVisit::query()->active()->where('host_user_id', $request->user()->id)->with('host')->oldest('arrived_at')->get(),
        );
    }

    public function decline(Request $request, GuestVisit $guestVisit, GuestVisitService $visits): JsonResponse
    {
        abort_unless($guestVisit->host_user_id === $request->user()->id, 404);

        try {
            $visits->decline($guestVisit, $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.guest.declined')]);
    }
}

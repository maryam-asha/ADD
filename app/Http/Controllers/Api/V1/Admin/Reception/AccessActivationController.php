<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessActivationController extends Controller
{
    public function activate(Request $request, AccessGrant $accessGrant, PasscodeIssuanceService $issuance): JsonResponse
    {
        try {
            $issuance->activate($accessGrant, $request->user());
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __('api.access.activated')]);
    }

    /**
     * Reception's read surface for the QR channel (final-review C3b): given
     * a booking, resolve the access grant reception needs to activate —
     * there was previously no way to discover a grant's id from a booking
     * at all, only the reverse (activate-by-grant-id).
     */
    public function forBooking(Booking $booking): JsonResponse
    {
        $grant = $booking->accessGrants()->latest('issued_at')->first();

        abort_if($grant === null, 404);

        return response()->json(['id' => $grant->id, 'status' => $grant->status->value]);
    }
}

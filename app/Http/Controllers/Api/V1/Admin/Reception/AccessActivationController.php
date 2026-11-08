<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\AccessNotifier;
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
     * A new door code for a booking — the old one expired unused, was
     * stopped, or the member lost it. Returns the new grant like forBooking(),
     * inside data, so the desk can activate it next.
     */
    public function reissue(Booking $booking, PasscodeIssuanceService $issuance, AccessNotifier $notices): JsonResponse
    {
        try {
            $grant = $issuance->reissueForBooking($booking);
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $notices->passcodeReady($booking->fresh(['space', 'user']));

        return response()->json(['data' => ['id' => $grant->id, 'status' => $grant->status->value]], 201);
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

        // Top-level as it always was (no `data` to move them into); the desk
        // reads the code's timing and lock from here, never the code.
        return response()->json([
            'id' => $grant->id,
            'status' => $grant->status->value,
            'lock_id' => $grant->lock_id,
            'issued_at' => $grant->issued_at?->toIso8601String(),
            'must_activate_by' => $grant->must_activate_by?->toIso8601String(),
            'activated_at' => $grant->activated_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'revoke_reason' => $grant->revoke_reason?->value,
        ]);
    }
}

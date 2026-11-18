<?php

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskPairingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\PairKioskRequest;
use App\Support\Security\Totp;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class KioskPairController extends Controller
{
    use LogsSensitiveActions;

    public function pair(PairKioskRequest $request, KioskPairingService $pairings, Totp $totp): JsonResponse
    {
        try {
            $pairing = $pairings->pair($request->validated('pairing_code'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('kiosk_paired', $pairing->kiosk);

        // Setup is over, so no staff credential stays on a device in a public
        // place. A failed code above keeps it, for the operator to retype.
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        // The only response that ever carries the secret; nothing between
        // here and the kiosk may keep a copy.
        return response()->json(['data' => [
            'kiosk_id' => $pairing->kiosk->id,
            'name' => $pairing->kiosk->name,
            'secret' => $pairing->secret,
            'totp' => [
                'algorithm' => Totp::ALGORITHM,
                'digits' => $totp->digits,
                'period' => $totp->period,
            ],
            'qr_prefix' => Kiosk::QR_PREFIX,
        ]])->header('Cache-Control', 'no-store');
    }
}

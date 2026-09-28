<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskPairingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class KioskPairingController extends Controller
{
    use LogsSensitiveActions;

    public function issue(Kiosk $kiosk, KioskPairingService $pairings): JsonResponse
    {
        $issued = $pairings->issueCode($kiosk);

        $this->logSensitiveAction('kiosk_pairing_code_issued', $kiosk);

        return response()->json(['data' => [
            'pairing_code' => $issued->code,
            'expires_at' => $issued->expiresAt->toIso8601String(),
        ]], 201);
    }

    public function revoke(Kiosk $kiosk, KioskPairingService $pairings): JsonResponse
    {
        $pairings->revoke($kiosk);

        $this->logSensitiveAction('kiosk_secret_revoked', $kiosk);

        return response()->json(['message' => __('api.kiosk.secret_revoked')]);
    }
}

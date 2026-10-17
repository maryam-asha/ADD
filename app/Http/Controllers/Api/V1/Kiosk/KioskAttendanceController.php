<?php

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Domain\Hr\Kiosk\KioskPunchIntake;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\StoreKioskPunchesRequest;
use Illuminate\Http\JsonResponse;

/**
 * Staff card scans from a reception kiosk (packages/add/hr attendance). The
 * request is signed with the kiosk's secret instead of a token, checked by
 * StoreKioskPunchesRequest (App\Domain\Hr\Kiosk\KioskRequestSignature).
 */
class KioskAttendanceController extends Controller
{
    public function store(StoreKioskPunchesRequest $request, KioskPunchIntake $intake): JsonResponse
    {
        $kiosk = $request->kiosk();
        $results = $intake->record($kiosk, $request->validated('punches'));

        // The kiosk's sign of life, as when a member scans its code.
        $kiosk->forceFill(['last_used_at' => now()])->save();

        return response()->json(['data' => $results]);
    }
}

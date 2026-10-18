<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Time\Services\AttendanceCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * An employee's QR card. The value comes back once, when issued: print it
 * then. Issuing again replaces the card.
 */
class AttendanceCardController extends Controller
{
    public function show(Employee $employee, AttendanceCards $cards): JsonResponse
    {
        $card = $cards->active($employee);

        return response()->json(['data' => [
            'has_card' => $card !== null,
            'issued_at' => $card?->created_at?->toIso8601String(),
        ]]);
    }

    public function store(Request $request, Employee $employee, AttendanceCards $cards): JsonResponse
    {
        return response()->json(['data' => [
            'qr_value' => $cards->issue($employee, $request->user()?->getAuthIdentifier()),
        ]], 201);
    }

    public function destroy(Employee $employee, AttendanceCards $cards): Response
    {
        $cards->revoke($employee);

        return response()->noContent();
    }
}

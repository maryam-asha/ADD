<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Services\AvailabilityBrowseService;
use App\Domain\Foundation\Models\Space;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\AvailabilityCalendarRequest;
use App\Http\Requests\Member\Booking\AvailabilityQuoteRequest;
use App\Http\Requests\Member\Booking\AvailabilitySlotsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Read-only. Nothing here holds a slot: a member who lingers on the
 * confirmation screen can still lose one, which is why the write path
 * re-checks under a row lock rather than trusting anything returned here.
 *
 * The locale comes from app()->getLocale(), which SetLocaleFromHeader has
 * already resolved from the `lang` header.
 */
class SpaceAvailabilityController extends Controller
{
    public function month(
        AvailabilityCalendarRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json($availability->calendar(
            $space,
            $request->validated('from'),
            $request->validated('to'),
            app()->getLocale(),
            $request->duration(),
        ));
    }

    public function day(
        AvailabilitySlotsRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json($availability->day(
            $space,
            $request->validated('date'),
            app()->getLocale(),
            $request->duration(),
        ));
    }

    public function quote(
        AvailabilityQuoteRequest $request,
        Space $space,
        AvailabilityBrowseService $availability,
    ): JsonResponse {
        return response()->json($availability->quote(
            $space,
            $request->validated('start_at'),
            app()->getLocale(),
        ));
    }
}

<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Time\Http\Resources\TimePeriodResource;
use Add\Hr\Time\Models\TimePeriod;
use Add\Hr\Time\Services\TimePeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Months with a state. A month with no row is open.
 */
class TimePeriodController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TimePeriodResource::collection(TimePeriod::query()->orderByDesc('year')->orderByDesc('month')->get());
    }

    public function lock(Request $request, int $year, int $month, TimePeriodService $periods): JsonResponse
    {
        abort_unless($month >= 1 && $month <= 12, 404);
        $periods->lock($year, $month, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::time.period_locked_ok')]);
    }

    public function unlock(int $year, int $month, TimePeriodService $periods): JsonResponse
    {
        abort_unless($month >= 1 && $month <= 12, 404);
        $periods->unlock($year, $month);

        return response()->json(['message' => __('hr::time.period_unlocked')]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Services\BookingPresentationService;
use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Booking\CoworkingPoolRequest;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * A branch's co-working seat pool, for reception and the dashboard: seats
 * open, taken and free now, the fewest free over the range, and the range
 * as segments — what staff read before selling a subscription or deciding
 * to lend a room (docs/decisions/coworking-seat-pool.md).
 */
class CoworkingPoolController extends Controller
{
    /** The longest range read at once: two months ahead. */
    private const MAX_DAYS = 62;

    public function show(CoworkingPoolRequest $request, Branch $branch, CoworkingPoolService $pool, LocalTime $time, BusinessHoursService $hours, BookingPresentationService $presentation): JsonResponse
    {
        $now = CarbonImmutable::now()->utc();
        $from = $request->validated('from') !== null ? CarbonImmutable::parse($request->validated('from'), 'UTC') : $now;
        $to = $request->validated('to') !== null
            ? CarbonImmutable::parse($request->validated('to'), 'UTC')
            : $this->endOfToday($branch, $now, $time, $hours);
        $to = min($to, $from->addDays(self::MAX_DAYS));

        $timeline = $pool->timeline($branch, $from, $to);
        // "Now" is read from its own instant: the range may not include it.
        $current = $from->lessThanOrEqualTo($now) && $to->greaterThan($now) ? $timeline : $pool->timeline($branch, $now, $now->addMinute());

        return response()->json(['data' => [
            'from' => $time->toLocal($from)->toIso8601String(),
            'to' => $time->toLocal($to)->toIso8601String(),
            'capacity_now' => $current->capacityAt($now),
            'usage_now' => $current->usageAt($now),
            'free_now' => $current->freeAt($now),
            'min_free' => $timeline->minFree(),
            // The same seats now, split by place: the branch's own, then
            // each room lent to co-working — where people sit
            // (docs/decisions/coworking-seat-pool.md, "Seating placement").
            'places_now' => array_map(fn (array $place) => [
                'kind' => $place['kind'],
                'spaces' => $place['spaces']->map(fn (Space $space) => $presentation->seat($space, app()->getLocale()))->values()->all(),
                'seats' => $place['seats'],
                'taken' => $place['taken'],
                'free' => $place['seats'] - $place['taken'],
                'until' => $place['until'] !== null ? $time->toLocal($place['until'])->toIso8601String() : null,
            ], $pool->placesAt($branch, $now)),
            'segments' => array_map(fn (array $segment) => [
                'start' => $time->toLocal($segment['start'])->toIso8601String(),
                'end' => $time->toLocal($segment['end'])->toIso8601String(),
                'capacity' => $segment['capacity'],
                'usage' => $segment['usage'],
                'free' => $segment['capacity'] - $segment['usage'],
            ], $timeline->segments()),
        ]]);
    }

    /**
     * Where "the rest of today" ends: the branch's last closing time today,
     * while it is still ahead — the hours after closing say nothing about
     * seats, and a room lent until closing would otherwise make the closed
     * hour read as the day's fewest free. Midnight when the branch keeps no
     * hours today, or has closed.
     */
    private function endOfToday(Branch $branch, CarbonImmutable $now, LocalTime $time, BusinessHoursService $hours): CarbonImmutable
    {
        $today = CarbonImmutable::instance($time->toLocal($now));
        $periods = $hours->periodsFor($today, $branch);

        if ($periods !== []) {
            $closing = $today->setTimeFromTimeString(max(array_column($periods, 'close_time')))->utc();

            if ($closing->greaterThan($now)) {
                return $closing;
            }
        }

        return $today->addDay()->startOfDay()->utc();
    }
}

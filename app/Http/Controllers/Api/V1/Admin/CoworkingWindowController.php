<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Booking\Services\CoworkingWindowService;
use App\Domain\Foundation\Models\Space;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Booking\StoreCoworkingWindowRequest;
use App\Http\Requests\Admin\Booking\UpdateCoworkingWindowRequest;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A room lent to co-working while the co-working space is overloaded: its
 * seats join the branch's pool for a stretch, and it takes no room bookings
 * meanwhile (docs/decisions/coworking-seat-pool.md).
 *
 * Does not extend AdminResourceController: the stretches belong to a room
 * and have no `order`, and every change passes through
 * CoworkingWindowService's pool checks.
 */
class CoworkingWindowController extends Controller
{
    public function __construct(
        private readonly LocalTime $time,
        private readonly CoworkingPoolService $pool,
    ) {}

    /** The room's stretches that have not ended yet, soonest first. */
    public function index(Space $space): JsonResponse
    {
        $windows = SpaceCoworkingWindow::query()
            ->where('space_id', $space->id)
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        return response()->json(['data' => $windows->map(fn (SpaceCoworkingWindow $window) => $this->present($window))]);
    }

    public function store(StoreCoworkingWindowRequest $request, Space $space, CoworkingWindowService $windows): JsonResponse
    {
        try {
            $window = $windows->lend(
                $space,
                Carbon::parse($request->validated('starts_at'), 'UTC'),
                Carbon::parse($request->validated('ends_at'), 'UTC'),
                (int) $request->validated('seats'),
                $request->user(),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['data' => $this->present($window)], 201);
    }

    public function update(UpdateCoworkingWindowRequest $request, SpaceCoworkingWindow $coworkingWindow, CoworkingWindowService $windows): JsonResponse
    {
        try {
            $windows->change(
                $coworkingWindow,
                Carbon::parse($request->validated('starts_at'), 'UTC'),
                Carbon::parse($request->validated('ends_at'), 'UTC'),
                (int) $request->validated('seats'),
                (bool) $request->validated('override', false),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.coworking.window_updated')]);
    }

    public function destroy(Request $request, SpaceCoworkingWindow $coworkingWindow, CoworkingWindowService $windows): JsonResponse
    {
        try {
            $windows->takeBack($coworkingWindow, $request->boolean('override'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.coworking.window_deleted')]);
    }

    /**
     * With how full the lent room is: `taken_now` while it is lent (null
     * before), and `taken_peak`, the most seats taken at once from now to
     * its end — what staff weigh before shortening or taking it back.
     *
     * @return array{id: int, space_id: int, starts_at: string, ends_at: string, seats: int, taken_now: ?int, taken_peak: int}
     */
    private function present(SpaceCoworkingWindow $window): array
    {
        $now = CarbonImmutable::now()->utc();
        $from = max($now, CarbonImmutable::instance($window->starts_at)->utc());
        $to = CarbonImmutable::instance($window->ends_at)->utc();
        $remaining = $to->greaterThan($from) ? $this->pool->roomTimeline($window, $from, $to) : null;
        $lentNow = $window->starts_at->lessThanOrEqualTo($now) && $window->ends_at->greaterThan($now);

        return [
            'id' => $window->id,
            'space_id' => $window->space_id,
            'starts_at' => $this->time->toLocal($window->starts_at)->toIso8601String(),
            'ends_at' => $this->time->toLocal($window->ends_at)->toIso8601String(),
            'seats' => $window->seats,
            'taken_now' => $lentNow ? ($remaining?->usageAt($now) ?? 0) : null,
            'taken_peak' => $remaining !== null ? $window->seats - min($window->seats, $remaining->minFree()) : 0,
        ];
    }
}

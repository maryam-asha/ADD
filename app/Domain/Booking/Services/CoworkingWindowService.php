<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Lending a room to co-working, and taking it back
 * (docs/decisions/coworking-seat-pool.md).
 *
 * A room is lent only over a stretch with no bookings of its own — its
 * seats cannot be promised twice — and a lent stretch is shortened, shrunk or
 * taken back only if the pool can do without the seats: subscriptions,
 * bookings and walk-ins may already count on them. Staff may override that,
 * by arrangement.
 *
 * Every change is made on the branch row every other pool decision locks,
 * then the room's.
 */
class CoworkingWindowService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly CoworkingPoolService $pool,
    ) {}

    public function lend(Space $room, CarbonInterface $startsAt, CarbonInterface $endsAt, int $seats, ?User $by): SpaceCoworkingWindow
    {
        return $this->locked($room, function (Space $room) use ($startsAt, $endsAt, $seats, $by) {
            $this->assertRoomIsFree($room, $startsAt, $endsAt);

            return SpaceCoworkingWindow::create([
                'space_id' => $room->id,
                'starts_at' => CarbonImmutable::instance($startsAt)->utc(),
                'ends_at' => CarbonImmutable::instance($endsAt)->utc(),
                'seats' => $seats,
                'created_by' => $by?->id,
            ]);
        });
    }

    public function change(SpaceCoworkingWindow $window, CarbonInterface $startsAt, CarbonInterface $endsAt, int $seats, bool $override = false): void
    {
        $this->locked($window->space, function (Space $room) use ($window, $startsAt, $endsAt, $seats, $override) {
            $this->assertRoomIsFree($room, $startsAt, $endsAt);

            $this->withoutOverbooking($room, $window, $override, fn () => $window->update([
                'starts_at' => CarbonImmutable::instance($startsAt)->utc(),
                'ends_at' => CarbonImmutable::instance($endsAt)->utc(),
                'seats' => $seats,
            ]), min($window->starts_at, $startsAt), max($window->ends_at, $endsAt));

            $this->reseat($room);
        });
    }

    public function takeBack(SpaceCoworkingWindow $window, bool $override = false): void
    {
        $this->locked($window->space, function (Space $room) use ($window, $override) {
            $this->withoutOverbooking($room, $window, $override, fn () => $window->delete(), $window->starts_at, $window->ends_at);

            $this->reseat($room);
        });
    }

    /**
     * The bookings placed in the room that its lent time no longer holds,
     * placed again — in it still if they fit, else the base seats or
     * another lent room (docs/decisions/coworking-seat-pool.md, "Seating
     * placement"). Only what is left of each: the past is not re-sat.
     */
    private function reseat(Space $room): void
    {
        $now = CarbonImmutable::now()->utc();

        Booking::query()
            ->blocking()
            ->with(['space.building.branch', 'user'])
            ->where('seat_space_id', $room->id)
            ->where('end_at', '>', $now)
            ->orderBy('start_at')
            ->orderBy('id')
            ->get()
            ->each(function (Booking $booking) use ($room, $now) {
                $seat = $this->pool->placeFor(
                    $booking->space->building->branch,
                    max(CarbonImmutable::instance($booking->start_at)->utc(), $now),
                    $booking->end_at,
                    $booking->user,
                    $booking->id,
                    $room->id,
                );

                if ($seat?->id !== $room->id) {
                    $booking->forceFill(['seat_space_id' => $seat?->id])->save();
                }
            });
    }

    /**
     * @template T
     *
     * @param  callable(Space): T  $action
     * @return T
     */
    private function locked(Space $room, callable $action): mixed
    {
        return DB::transaction(function () use ($room, $action) {
            Branch::query()->whereKey($room->building->branch_id)->lockForUpdate()->first();

            return $action(Space::query()->whereKey($room->id)->lockForUpdate()->firstOrFail());
        });
    }

    /** A room is lent only over a stretch it has no bookings in. */
    private function assertRoomIsFree(Space $room, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $rules = $this->availability->rulesFor($room);
        $bookings = $this->availability->blockingBookings($room, $startsAt, $endsAt, $rules);

        if ($this->availability->overlapCount($bookings, $startsAt, $endsAt) > 0) {
            throw new ReceptionActionException('api.coworking.room_has_bookings');
        }
    }

    /**
     * Makes the change, then refuses it if it leaves the pool shorter of
     * seats at some moment than it already was — the seats were counted on.
     * A pool already overbooked (a capacity cut, say) does not block a change
     * that makes it no worse.
     */
    private function withoutOverbooking(Space $room, SpaceCoworkingWindow $window, bool $override, callable $change, CarbonInterface $from, CarbonInterface $to): void
    {
        $branch = $room->building->branch;
        $from = max(CarbonImmutable::instance($from)->utc(), CarbonImmutable::now()->utc());
        $to = CarbonImmutable::instance($to)->utc();

        $before = $to->greaterThan($from) ? $this->pool->timeline($branch, $from, $to)->minFree() : 0;

        $change();

        if ($override || $to->lessThanOrEqualTo($from)) {
            return;
        }

        if ($this->pool->timeline($branch, $from, $to)->minFree() < min(0, $before)) {
            throw new ReceptionActionException('api.coworking.would_overbook');
        }
    }
}

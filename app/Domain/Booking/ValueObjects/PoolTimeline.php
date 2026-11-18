<?php

namespace App\Domain\Booking\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A branch's co-working seat pool over a range of time: how many seats are
 * open and how many are taken, as consecutive segments.
 *
 * Pure arithmetic over intervals — CoworkingPoolService gathers them from
 * the database. At any instant:
 *
 *     usage = Σ over memberships of (held ? max(seats, present on it) : present on it)
 *             + people present on no membership
 *
 * so a subscriber's own booking or walk-in sits in the seat their
 * subscription already holds, a company with more people inside than seats
 * spills the rest into the pool, and presence outside the plan's window
 * counts like anyone else's (docs/decisions/coworking-seat-pool.md).
 *
 * Built by a sweep over the intervals' boundaries — O(n log n), never a
 * loop over minutes — and every interval is half-open, [start, end).
 */
final class PoolTimeline
{
    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, capacity: int, usage: int}>  $segments
     */
    private function __construct(private readonly array $segments) {}

    /**
     * @param  iterable<array{start: CarbonInterface, end: CarbonInterface, seats: int}>  $supply  seats open: a co_space's capacity, a room's co-working window
     * @param  iterable<array{membership: int|string, start: CarbonInterface, end: CarbonInterface, seats: int}>  $holds  a subscription holding its seats, one entry per stretch its plan is open
     * @param  iterable<array{start: CarbonInterface, end: CarbonInterface, membership: int|string|null}>  $presences  a booking or walk-in, on the membership that covers it, or null
     */
    public static function build(CarbonInterface $from, CarbonInterface $to, iterable $supply, iterable $holds, iterable $presences): self
    {
        $rangeStart = $from->getTimestamp();
        $rangeEnd = $to->getTimestamp();

        if ($rangeEnd <= $rangeStart) {
            return new self([]);
        }

        // [time => list of changes]; a change is [kind, membership, delta].
        $events = [];
        $seats = [];

        $add = function (CarbonInterface $start, CarbonInterface $end, string $kind, int|string|null $membership, int $delta) use (&$events, $rangeStart, $rangeEnd): void {
            $s = max($rangeStart, $start->getTimestamp());
            $e = min($rangeEnd, $end->getTimestamp());

            if ($e <= $s) {
                return;
            }

            $events[$s][] = [$kind, $membership, $delta];
            $events[$e][] = [$kind, $membership, -$delta];
        };

        foreach ($supply as $interval) {
            $add($interval['start'], $interval['end'], 'capacity', null, $interval['seats']);
        }

        foreach ($holds as $hold) {
            $seats[$hold['membership']] = $hold['seats'];
            $add($hold['start'], $hold['end'], 'held', $hold['membership'], 1);
        }

        foreach ($presences as $presence) {
            $membership = $presence['membership'];
            $add($presence['start'], $presence['end'], $membership === null ? 'uncovered' : 'covered', $membership, 1);
        }

        // The range's own ends are boundaries even where nothing changes.
        $events[$rangeStart] ??= [];
        $events[$rangeEnd] ??= [];
        ksort($events);

        $capacity = 0;
        $uncovered = 0;
        $membershipUsage = 0;
        /** @var array<int|string, int> $held */
        $held = [];
        /** @var array<int|string, int> $covered */
        $covered = [];

        // By reference: the counters change as the sweep goes.
        $usageOf = function (int|string $membership) use (&$held, &$covered, $seats): int {
            return ($held[$membership] ?? 0) > 0
                ? max($seats[$membership] ?? 0, $covered[$membership] ?? 0)
                : ($covered[$membership] ?? 0);
        };

        $segments = [];
        $previous = null;

        foreach ($events as $time => $changes) {
            if ($previous !== null && $time > $previous) {
                $usage = $membershipUsage + $uncovered;
                $last = array_key_last($segments);

                if ($last !== null && $segments[$last]['capacity'] === $capacity && $segments[$last]['usage'] === $usage) {
                    $segments[$last]['end'] = $time;
                } else {
                    $segments[] = ['start' => $previous, 'end' => $time, 'capacity' => $capacity, 'usage' => $usage];
                }
            }

            foreach ($changes as [$kind, $membership, $delta]) {
                match ($kind) {
                    'capacity' => $capacity += $delta,
                    'uncovered' => $uncovered += $delta,
                    default => null,
                };

                if ($kind === 'held' || $kind === 'covered') {
                    $membershipUsage -= $usageOf($membership);

                    if ($kind === 'held') {
                        $held[$membership] = ($held[$membership] ?? 0) + $delta;
                    } else {
                        $covered[$membership] = ($covered[$membership] ?? 0) + $delta;
                    }

                    $membershipUsage += $usageOf($membership);
                }
            }

            $previous = $time;
        }

        return new self(array_map(fn (array $segment) => [
            ...$segment,
            'start' => CarbonImmutable::createFromTimestampUTC($segment['start']),
            'end' => CarbonImmutable::createFromTimestampUTC($segment['end']),
        ], $segments));
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, capacity: int, usage: int}>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function capacityAt(CarbonInterface $at): int
    {
        return $this->segmentAt($at)['capacity'] ?? 0;
    }

    public function usageAt(CarbonInterface $at): int
    {
        return $this->segmentAt($at)['usage'] ?? 0;
    }

    public function freeAt(CarbonInterface $at): int
    {
        return $this->capacityAt($at) - $this->usageAt($at);
    }

    /** The fewest free seats anywhere in the range; negative when overbooked. */
    public function minFree(): int
    {
        return $this->segments === []
            ? 0
            : min(array_map(fn (array $segment) => $segment['capacity'] - $segment['usage'], $this->segments));
    }

    /** The fewest free seats anywhere in [$start, $end). */
    public function minFreeBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        $free = null;

        foreach ($this->segments as $segment) {
            if ($segment['start']->lessThan($end) && $segment['end']->greaterThan($start)) {
                $free = min($free ?? PHP_INT_MAX, $segment['capacity'] - $segment['usage']);
            }
        }

        return $free ?? 0;
    }

    /** The first moment more seats are taken than are open, or null. */
    public function firstShortfall(): ?CarbonImmutable
    {
        foreach ($this->segments as $segment) {
            if ($segment['usage'] > $segment['capacity']) {
                return $segment['start'];
            }
        }

        return null;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, capacity: int, usage: int}|null
     */
    private function segmentAt(CarbonInterface $at): ?array
    {
        foreach ($this->segments as $segment) {
            if ($segment['start']->lessThanOrEqualTo($at) && $segment['end']->greaterThan($at)) {
                return $segment;
            }
        }

        return null;
    }
}

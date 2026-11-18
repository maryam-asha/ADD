<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\ValueObjects\PoolTimeline;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The co-working seat pool's arithmetic, on plain intervals — no database.
 *
 * At any instant: usage = Σ over memberships of
 * (held ? max(seats, present on it) : present on it) + people present on no
 * membership. So a subscriber's own booking or walk-in is not counted twice,
 * a company with more people inside than seats spills the rest into the pool,
 * and presence outside the plan's window counts like anyone else's.
 */
class PoolTimelineTest extends TestCase
{
    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-01 {$time}", 'UTC');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $supply
     * @param  list<array{0: int|string, 1: string, 2: string, 3: int}>  $holds
     * @param  list<array{0: string, 1: string, 2: int|string|null}>  $presences
     */
    private function timeline(array $supply, array $holds = [], array $presences = [], string $from = '08:00', string $to = '20:00'): PoolTimeline
    {
        return PoolTimeline::build(
            $this->at($from),
            $this->at($to),
            array_map(fn (array $s) => ['start' => $this->at($s[0]), 'end' => $this->at($s[1]), 'seats' => $s[2]], $supply),
            array_map(fn (array $h) => ['membership' => $h[0], 'start' => $this->at($h[1]), 'end' => $this->at($h[2]), 'seats' => $h[3]], $holds),
            array_map(fn (array $p) => ['start' => $this->at($p[0]), 'end' => $this->at($p[1]), 'membership' => $p[2]], $presences),
        );
    }

    public function test_an_empty_pool_has_no_seats_and_no_usage(): void
    {
        $timeline = $this->timeline([]);

        $this->assertSame(0, $timeline->capacityAt($this->at('10:00')));
        $this->assertSame(0, $timeline->usageAt($this->at('10:00')));
        $this->assertSame(0, $timeline->minFree());
        $this->assertNull($timeline->firstShortfall());
    }

    /** Two co_spaces, and a room lent to co-working for the afternoon. */
    public function test_capacity_is_the_sum_of_what_is_open_at_each_moment(): void
    {
        $timeline = $this->timeline([
            ['08:00', '20:00', 10],
            ['08:00', '20:00', 6],
            ['14:00', '18:00', 4],
        ]);

        $this->assertSame(16, $timeline->capacityAt($this->at('09:00')));
        $this->assertSame(20, $timeline->capacityAt($this->at('15:00')));
        $this->assertSame(16, $timeline->capacityAt($this->at('18:00')), 'half-open: the window has ended at 18:00');
    }

    public function test_a_held_subscription_takes_its_seats_only_while_it_is_held(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 10]],
            [[1, '12:00', '16:00', 1], [2, '08:00', '20:00', 3]],
        );

        $this->assertSame(3, $timeline->usageAt($this->at('09:00')));
        $this->assertSame(4, $timeline->usageAt($this->at('13:00')));
        $this->assertSame(6, $timeline->minFree());
    }

    /** A subscriber booking or walking in sits in the seat already held. */
    public function test_a_subscribers_own_presence_is_not_counted_twice(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 10]],
            [[1, '08:00', '20:00', 1]],
            [['10:00', '12:00', 1]],
        );

        $this->assertSame(1, $timeline->usageAt($this->at('11:00')));
    }

    /** A company holding 2 seats with 3 people inside uses 3. */
    public function test_a_company_beyond_its_seats_spills_into_the_pool(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 10]],
            [['company', '08:00', '20:00', 2]],
            [['10:00', '12:00', 'company'], ['10:00', '12:00', 'company'], ['11:00', '12:00', 'company']],
        );

        $this->assertSame(2, $timeline->usageAt($this->at('10:30')));
        $this->assertSame(3, $timeline->usageAt($this->at('11:30')));
        $this->assertSame(2, $timeline->usageAt($this->at('13:00')), 'still held after they leave');
    }

    /** Outside the plan's window, a subscriber inside takes a seat like anyone. */
    public function test_presence_outside_the_held_window_takes_a_seat(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 10]],
            [[1, '16:00', '20:00', 1]],
            [['10:00', '17:00', 1]],
        );

        $this->assertSame(1, $timeline->usageAt($this->at('11:00')));
        $this->assertSame(1, $timeline->usageAt($this->at('16:30')), 'inside the window the held seat covers them');
    }

    public function test_people_on_no_membership_each_take_a_seat(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 2]],
            [],
            [['10:00', '12:00', null], ['11:00', '13:00', null], ['11:30', '12:30', null]],
        );

        $this->assertSame(3, $timeline->usageAt($this->at('11:45')));
        $this->assertSame(-1, $timeline->minFree());
        $this->assertSame(-1, $timeline->minFreeBetween($this->at('11:00'), $this->at('13:00')));
        $this->assertSame(1, $timeline->minFreeBetween($this->at('08:00'), $this->at('11:00')));
    }

    public function test_the_first_shortfall_is_the_first_moment_over_capacity(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 1], ['08:00', '15:00', 1]],
            [[1, '08:00', '20:00', 1]],
            [['14:00', '16:00', null]],
        );

        $this->assertEquals($this->at('15:00'), $timeline->firstShortfall());
    }

    public function test_segments_merge_neighbours_that_do_not_differ(): void
    {
        $timeline = $this->timeline(
            [['08:00', '20:00', 5]],
            [[1, '08:00', '12:00', 1], [2, '12:00', '20:00', 1]],
        );

        $this->assertSame(
            [['start' => '08:00', 'end' => '20:00', 'capacity' => 5, 'usage' => 1]],
            array_map(fn (array $segment) => [
                'start' => $segment['start']->format('H:i'),
                'end' => $segment['end']->format('H:i'),
                'capacity' => $segment['capacity'],
                'usage' => $segment['usage'],
            ], $timeline->segments()),
        );
    }

    /** Intervals reaching outside the range are clipped to it. */
    public function test_intervals_are_clipped_to_the_range(): void
    {
        $timeline = $this->timeline(
            [['06:00', '22:00', 5]],
            [[1, '06:00', '22:00', 1]],
            from: '10:00',
            to: '12:00',
        );

        $this->assertCount(1, $timeline->segments());
        $this->assertEquals($this->at('10:00'), $timeline->segments()[0]['start']);
        $this->assertEquals($this->at('12:00'), $timeline->segments()[0]['end']);
    }

    /** 500 evening subscriptions over a 30-day period, swept at once. */
    public function test_a_month_of_windowed_subscriptions_is_swept_quickly(): void
    {
        $from = CarbonImmutable::parse('2026-10-01 00:00', 'UTC');
        $holds = [];

        for ($membership = 1; $membership <= 500; $membership++) {
            for ($day = 0; $day < 30; $day++) {
                $holds[] = [
                    'membership' => $membership,
                    'start' => $from->addDays($day)->setTime(17, 0),
                    'end' => $from->addDays($day)->setTime(21, 0),
                    'seats' => 1,
                ];
            }
        }

        $started = microtime(true);
        $timeline = PoolTimeline::build($from, $from->addDays(30), [['start' => $from, 'end' => $from->addDays(30), 'seats' => 600]], $holds, []);

        $this->assertSame(100, $timeline->minFree());
        $this->assertLessThan(2.0, microtime(true) - $started);
    }
}

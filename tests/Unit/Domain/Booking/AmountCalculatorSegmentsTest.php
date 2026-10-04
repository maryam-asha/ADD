<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Booking\ValueObjects\RateSegment;
use App\Domain\Foundation\Models\Space;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * A range priced at more than one rate — part of it inside a sale — is
 * still rounded up ONCE, over the whole (Invariant 2): rounding each
 * segment up on its own would charge a partial point twice.
 */
class AmountCalculatorSegmentsTest extends TestCase
{
    public function test_segments_are_summed_before_the_single_ceil(): void
    {
        $calculator = new AmountCalculator;

        // 30 min at 10/h + 30 min at 8/h = 5 + 4.
        $this->assertSame(9, $calculator->forSegments([new RateSegment(1800, 10), new RateSegment(1800, 8)]));

        // 1 s at 10/h twice is 20/3600 of a point: one point, not two.
        $this->assertSame(1, $calculator->forSegments([new RateSegment(1, 10), new RateSegment(1, 10)]));

        // 37 min at 12/h = 7.4, plus 23 min at 6/h = 2.3: 9.7 → 10.
        $this->assertSame(10, $calculator->forSegments([new RateSegment(37 * 60, 12), new RateSegment(23 * 60, 6)]));
    }

    public function test_free_or_empty_segments_cost_nothing(): void
    {
        $calculator = new AmountCalculator;

        $this->assertSame(0, $calculator->forSegments([]));
        $this->assertSame(0, $calculator->forSegments([new RateSegment(3600, 0)]));
        $this->assertSame(0, $calculator->forSegments([new RateSegment(0, 10)]));
        $this->assertSame(5, $calculator->forSegments([new RateSegment(3600, 0), new RateSegment(1800, 10)]));
    }

    public function test_for_range_is_a_single_segment_at_the_space_rate(): void
    {
        $calculator = new AmountCalculator;
        $space = new Space(['hourly_rate_points' => 12]);
        $start = CarbonImmutable::parse('2026-10-05 10:00:00');
        $end = $start->addMinutes(37);

        $this->assertSame(8, $calculator->forRange($space, $start, $end));
        $this->assertSame($calculator->forSegments([new RateSegment(37 * 60, 12)]), $calculator->forRange($space, $start, $end));
    }
}

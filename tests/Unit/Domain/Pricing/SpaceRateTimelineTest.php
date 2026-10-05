<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Booking\ValueObjects\RateSegment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Pricing\Services\SpaceRateTimeline;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * A space's sale is priced by USAGE time: the seconds of a range inside the
 * sale window at the sale rate, the rest at the normal rate
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class SpaceRateTimelineTest extends TestCase
{
    private function space(array $attributes = []): Space
    {
        return new Space(array_merge([
            'hourly_rate_points' => 100,
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => CarbonImmutable::parse('2026-10-05 00:00:00'),
            'sale_ends_at' => CarbonImmutable::parse('2026-10-12 00:00:00'),
        ], $attributes));
    }

    /** @return list<array{0: int, 1: int}> */
    private function segments(Space $space, string $from, string $to): array
    {
        return array_map(
            fn (RateSegment $s) => [$s->seconds, $s->ratePoints],
            (new SpaceRateTimeline)->segments($space, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
        );
    }

    public function test_a_range_before_or_after_the_sale_is_all_at_the_normal_rate(): void
    {
        $this->assertSame([[7200, 100]], $this->segments($this->space(), '2026-10-04 10:00:00', '2026-10-04 12:00:00'));
        $this->assertSame([[7200, 100]], $this->segments($this->space(), '2026-10-12 00:00:00', '2026-10-12 02:00:00'));
    }

    public function test_a_range_inside_the_sale_is_all_at_the_sale_rate(): void
    {
        $this->assertSame([[7200, 80]], $this->segments($this->space(), '2026-10-06 10:00:00', '2026-10-06 12:00:00'));
    }

    public function test_a_range_crossing_the_sale_end_is_split(): void
    {
        $this->assertSame(
            [[3600, 80], [3600, 100]],
            $this->segments($this->space(), '2026-10-11 23:00:00', '2026-10-12 01:00:00'),
        );
    }

    public function test_a_range_crossing_the_sale_start_is_split(): void
    {
        $this->assertSame(
            [[1800, 80], [5400, 100]],
            $this->segments($this->space(), '2026-10-04 22:30:00', '2026-10-05 00:30:00'),
        );
    }

    public function test_a_free_sale_is_a_zero_rate_segment(): void
    {
        $this->assertSame([[3600, 0]], $this->segments($this->space(['sale_hourly_rate_points' => 0]), '2026-10-06 10:00:00', '2026-10-06 11:00:00'));
    }

    /** A "sale" never raises a price: a sale rate at or above the normal one is ignored. */
    public function test_a_sale_rate_above_the_normal_rate_is_ignored(): void
    {
        $this->assertSame([[3600, 100]], $this->segments($this->space(['sale_hourly_rate_points' => 120]), '2026-10-06 10:00:00', '2026-10-06 11:00:00'));
    }

    public function test_a_space_with_no_rate_has_no_segments(): void
    {
        $this->assertSame([], $this->segments($this->space(['hourly_rate_points' => null]), '2026-10-06 10:00:00', '2026-10-06 11:00:00'));
    }

    public function test_a_space_without_a_sale_is_one_segment(): void
    {
        $space = $this->space(['sale_hourly_rate_points' => null, 'sale_starts_at' => null, 'sale_ends_at' => null]);

        $this->assertSame([[3600, 100]], $this->segments($space, '2026-10-06 10:00:00', '2026-10-06 11:00:00'));
    }
}

<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Booking\ValueObjects\RateSegment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Services\SpaceRateTimeline;
use App\Domain\Pricing\ValueObjects\CouponTerms;
use App\Domain\Pricing\ValueObjects\SaleWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * An offer on time in a space is priced by USAGE time: the seconds of a
 * range inside the offer's window at the offer's exact rate, the rest at
 * the normal rate
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md, E3).
 */
class SpaceRateTimelineTest extends TestCase
{
    /** 20 points off from 2026-10-05 to 2026-10-12: a room at 100 at 80. */
    private function weekAt80(): CouponTerms
    {
        return new CouponTerms(CouponDiscountType::AmountOff, 20, null, SaleWindow::between(
            CarbonImmutable::parse('2026-10-05 00:00:00'),
            CarbonImmutable::parse('2026-10-12 00:00:00'),
        ));
    }

    /** @return list<array{0: int, 1: string}> */
    private function segments(?CouponTerms $offer, string $from, string $to, ?int $rate = 100): array
    {
        return array_map(
            fn (RateSegment $s) => [$s->seconds, $s->rate],
            (new SpaceRateTimeline)->segments(new Space(['hourly_rate_points' => $rate]), CarbonImmutable::parse($from), CarbonImmutable::parse($to), $offer),
        );
    }

    public function test_a_range_before_or_after_the_offer_is_all_at_the_normal_rate(): void
    {
        $this->assertSame([[7200, '100']], $this->segments($this->weekAt80(), '2026-10-04 10:00:00', '2026-10-04 12:00:00'));
        $this->assertSame([[7200, '100']], $this->segments($this->weekAt80(), '2026-10-12 00:00:00', '2026-10-12 02:00:00'));
    }

    public function test_a_range_inside_the_offer_is_all_at_the_offer_rate(): void
    {
        $this->assertSame([[7200, '80']], $this->segments($this->weekAt80(), '2026-10-06 10:00:00', '2026-10-06 12:00:00'));
    }

    public function test_a_range_crossing_the_offer_end_is_split(): void
    {
        $this->assertSame([[3600, '80'], [3600, '100']], $this->segments($this->weekAt80(), '2026-10-11 23:00:00', '2026-10-12 01:00:00'));
    }

    public function test_a_range_crossing_the_offer_start_is_split(): void
    {
        $this->assertSame([[1800, '80'], [5400, '100']], $this->segments($this->weekAt80(), '2026-10-04 22:30:00', '2026-10-05 00:30:00'));
    }

    public function test_a_percentage_offer_charges_an_exact_rate_inside_its_window(): void
    {
        $offer = new CouponTerms(CouponDiscountType::Percentage, 10, null, SaleWindow::between(CarbonImmutable::parse('2026-10-06 10:00:00'), CarbonImmutable::parse('2026-10-06 11:00:00')));

        $this->assertSame([[3600, '13.50'], [3600, '15']], $this->segments($offer, '2026-10-06 10:00:00', '2026-10-06 12:00:00', 15));
    }

    public function test_an_open_ended_offer_covers_the_whole_range(): void
    {
        $offer = new CouponTerms(CouponDiscountType::AmountOff, 30, null, SaleWindow::between(null, null));

        $this->assertSame([[7200, '70']], $this->segments($offer, '2026-10-06 10:00:00', '2026-10-06 12:00:00'));
    }

    /** An offer that would leave the rate as it is is ignored. */
    public function test_an_offer_no_lower_than_the_rate_is_ignored(): void
    {
        $offer = new CouponTerms(CouponDiscountType::AmountOff, 0, null, SaleWindow::between(null, null));

        $this->assertSame([[3600, '100']], $this->segments($offer, '2026-10-06 10:00:00', '2026-10-06 11:00:00'));
    }

    public function test_a_space_with_no_rate_has_no_segments(): void
    {
        $this->assertSame([], $this->segments($this->weekAt80(), '2026-10-06 10:00:00', '2026-10-06 11:00:00', null));
    }

    public function test_no_offer_is_one_segment_at_the_rate(): void
    {
        $this->assertSame([[3600, '100']], $this->segments(null, '2026-10-06 10:00:00', '2026-10-06 11:00:00'));
    }
}

<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\ValueObjects\SaleWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SaleWindowTest extends TestCase
{
    private function window(): SaleWindow
    {
        return SaleWindow::of(80, CarbonImmutable::parse('2026-10-05 00:00:00'), CarbonImmutable::parse('2026-10-12 00:00:00'));
    }

    /** All three or none: a half-configured sale is no sale at all. */
    public function test_a_sale_needs_its_price_and_both_ends(): void
    {
        $start = CarbonImmutable::parse('2026-10-05 00:00:00');
        $end = CarbonImmutable::parse('2026-10-12 00:00:00');

        $this->assertNull(SaleWindow::of(null, $start, $end));
        $this->assertNull(SaleWindow::of(80, null, $end));
        $this->assertNull(SaleWindow::of(80, $start, null));
        $this->assertNull(SaleWindow::of(80, $end, $start));
        $this->assertNull(SaleWindow::of(80, $start, $start));
        $this->assertNotNull(SaleWindow::of(0, $start, $end));
    }

    /** Live from its first instant, over at its last: [starts_at, ends_at). */
    public function test_live_includes_the_start_and_excludes_the_end(): void
    {
        $sale = $this->window();

        $this->assertFalse($sale->isLiveAt(CarbonImmutable::parse('2026-10-04 23:59:59')));
        $this->assertTrue($sale->isLiveAt(CarbonImmutable::parse('2026-10-05 00:00:00')));
        $this->assertTrue($sale->isLiveAt(CarbonImmutable::parse('2026-10-11 23:59:59')));
        $this->assertFalse($sale->isLiveAt(CarbonImmutable::parse('2026-10-12 00:00:00')));
    }

    public function test_clip_returns_the_part_of_a_range_inside_the_sale(): void
    {
        $sale = $this->window();

        [$from, $to] = $sale->clip(CarbonImmutable::parse('2026-10-11 22:00:00'), CarbonImmutable::parse('2026-10-12 02:00:00'));
        $this->assertSame('2026-10-11 22:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-12 00:00:00', $to->toDateTimeString());

        [$from, $to] = $sale->clip(CarbonImmutable::parse('2026-10-04 23:00:00'), CarbonImmutable::parse('2026-10-05 01:00:00'));
        $this->assertSame('2026-10-05 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-05 01:00:00', $to->toDateTimeString());

        [$from, $to] = $sale->clip(CarbonImmutable::parse('2026-10-06 10:00:00'), CarbonImmutable::parse('2026-10-06 12:00:00'));
        $this->assertSame('2026-10-06 10:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-06 12:00:00', $to->toDateTimeString());
    }

    public function test_clip_is_null_when_the_range_misses_the_sale(): void
    {
        $sale = $this->window();

        $this->assertNull($sale->clip(CarbonImmutable::parse('2026-10-01 10:00:00'), CarbonImmutable::parse('2026-10-05 00:00:00')));
        $this->assertNull($sale->clip(CarbonImmutable::parse('2026-10-12 00:00:00'), CarbonImmutable::parse('2026-10-12 03:00:00')));
    }

    /** An offer's window may be open at either end: open means always. */
    public function test_an_open_window_is_live_and_clips_nothing_off(): void
    {
        $open = SaleWindow::between(null, null);
        $from = CarbonImmutable::parse('2026-10-06 10:00:00');
        $to = CarbonImmutable::parse('2026-10-06 12:00:00');

        $this->assertTrue($open->isLiveAt($from));
        [$a, $b] = $open->clip($from, $to);
        $this->assertSame('2026-10-06 10:00:00', $a->toDateTimeString());
        $this->assertSame('2026-10-06 12:00:00', $b->toDateTimeString());
        $this->assertNull($open->endsAt);
    }

    public function test_a_window_open_at_one_end_clips_at_the_other(): void
    {
        $from = CarbonImmutable::parse('2026-10-06 10:00:00');
        $to = CarbonImmutable::parse('2026-10-06 12:00:00');

        [$a] = SaleWindow::between(CarbonImmutable::parse('2026-10-06 11:00:00'), null)->clip($from, $to);
        $this->assertSame('2026-10-06 11:00:00', $a->toDateTimeString());

        [, $b] = SaleWindow::between(null, CarbonImmutable::parse('2026-10-06 11:30:00'))->clip($from, $to);
        $this->assertSame('2026-10-06 11:30:00', $b->toDateTimeString());
    }

    public function test_overlaps_is_false_for_a_range_wholly_outside(): void
    {
        $window = SaleWindow::between(CarbonImmutable::parse('2026-10-06 11:00:00'), CarbonImmutable::parse('2026-10-06 12:00:00'));

        $this->assertFalse($window->overlaps(CarbonImmutable::parse('2026-10-06 09:00:00'), CarbonImmutable::parse('2026-10-06 11:00:00')));
        $this->assertTrue($window->overlaps(CarbonImmutable::parse('2026-10-06 09:00:00'), CarbonImmutable::parse('2026-10-06 11:00:01')));
    }
}

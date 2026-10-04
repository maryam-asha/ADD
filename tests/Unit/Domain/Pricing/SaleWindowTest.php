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
}

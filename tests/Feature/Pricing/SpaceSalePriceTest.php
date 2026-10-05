<?php

namespace Tests\Feature\Pricing;

use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A space's sale is three columns on the space itself: a sale hourly rate
 * and the window it runs in (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class SpaceSalePriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_space_exposes_its_sale_window(): void
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => Carbon::parse('2026-10-05 00:00:00'),
            'sale_ends_at' => Carbon::parse('2026-10-12 00:00:00'),
        ])->fresh();

        $sale = $space->saleWindow();

        $this->assertNotNull($sale);
        $this->assertSame(80, $sale->points);
        $this->assertSame('2026-10-05 00:00:00', $sale->startsAt->toDateTimeString());
        $this->assertSame('2026-10-12 00:00:00', $sale->endsAt->toDateTimeString());
    }

    public function test_a_space_without_a_sale_has_no_window(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100])->fresh();

        $this->assertNull($space->sale_hourly_rate_points);
        $this->assertNull($space->saleWindow());
    }
}

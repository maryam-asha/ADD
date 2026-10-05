<?php

namespace Tests\Feature\Pricing;

use App\Domain\Membership\Models\Plan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "This package is 120, but 100 for two days" — a plan's sale is three
 * columns on the plan (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class PlanSaleColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_exposes_its_sale_window(): void
    {
        $plan = Plan::factory()->create([
            'price_points' => 120,
            'sale_price_points' => 100,
            'sale_starts_at' => Carbon::parse('2026-10-05 00:00:00'),
            'sale_ends_at' => Carbon::parse('2026-10-07 00:00:00'),
        ])->fresh();

        $sale = $plan->saleWindow();

        $this->assertNotNull($sale);
        $this->assertSame(100, $sale->points);
        $this->assertTrue($sale->isLiveAt(Carbon::parse('2026-10-06 12:00:00')));
        $this->assertFalse($sale->isLiveAt(Carbon::parse('2026-10-07 00:00:00')));
    }

    public function test_a_plan_without_a_sale_has_no_window(): void
    {
        $this->assertNull(Plan::factory()->create(['price_points' => 120])->fresh()->saleWindow());
    }
}

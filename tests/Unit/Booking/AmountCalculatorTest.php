<?php

namespace Tests\Unit\Booking;

use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmountCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whole_hour_charges_the_exact_rate(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => 120]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(120, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addHour()));
    }

    public function test_ninety_minutes_charges_one_and_a_half_times(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => 12]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(18, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addMinutes(90)));
    }

    public function test_a_partial_point_rounds_up(): void
    {
        // 37 min at 12 pts/hr = 7.4 -> 8
        $space = Space::factory()->create(['hourly_rate_points' => 12]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(8, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addMinutes(37)));
    }

    public function test_a_space_with_no_rate_is_free(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => null]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(0, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addHour()));
    }

    /**
     * Regression: hours were truncated to 6 decimals before the multiply, so an
     * amount mathematically just above an integer compared as exactly equal and
     * was never ceiled. 689s at 209 pts/hr is exactly 40.00027... points.
     */
    public function test_a_residue_below_the_old_precision_floor_still_rounds_up(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => 209]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(41, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addSeconds(689)));
    }

    public function test_a_second_sub_precision_residue_also_rounds_up(): void
    {
        // 2917s at 253 pts/hr = 205.0036... points
        $space = Space::factory()->create(['hourly_rate_points' => 253]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(206, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addSeconds(2917)));
    }

    public function test_it_matches_an_exact_integer_reference_across_many_rates(): void
    {
        $start = Carbon::parse('2026-09-12 10:00:00');
        $calculator = app(AmountCalculator::class);

        foreach ([209, 227, 253, 263, 271, 281, 30, 120, 180, 1200] as $rate) {
            $space = Space::factory()->create(['hourly_rate_points' => $rate]);

            foreach ([1, 59, 689, 1801, 2327, 2917, 3599, 3600, 7201] as $seconds) {
                $product = $seconds * $rate;
                $expected = intdiv($product, 3600) + ($product % 3600 > 0 ? 1 : 0);

                $this->assertSame(
                    $expected,
                    $calculator->forRange($space, $start, $start->copy()->addSeconds($seconds)),
                    "rate {$rate} over {$seconds}s"
                );
            }
        }
    }

    public function test_a_zero_rate_is_free(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => 0]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(0, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addHour()));
    }
}

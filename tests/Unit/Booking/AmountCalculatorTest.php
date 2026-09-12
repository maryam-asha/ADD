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
}

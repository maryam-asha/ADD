<?php

namespace Tests\Unit\Rules;

use App\Rules\NoOverlappingPeriod;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NoOverlappingPeriodTest extends TestCase
{
    public function test_it_passes_when_no_existing_periods_overlap(): void
    {
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '12:00']],
            openTime: '13:00',
        );

        $validator = Validator::make(['close_time' => '17:00'], ['close_time' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_it_fails_when_the_new_period_overlaps_an_existing_one(): void
    {
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '12:00']],
            openTime: '11:00',
        );

        $validator = Validator::make(['close_time' => '15:00'], ['close_time' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_it_fails_when_the_new_period_exactly_matches_an_existing_one(): void
    {
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '12:00']],
            openTime: '08:00',
        );

        $validator = Validator::make(['close_time' => '12:00'], ['close_time' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_it_passes_when_the_new_period_is_adjacent_but_not_overlapping(): void
    {
        // Touching at a single boundary point does not count as overlap —
        // both edges are inclusive, so 08:00-12:00 and 12:00-16:00 would
        // share the instant 12:00, which is a real overlap under an
        // inclusive-inclusive convention. Use a genuinely non-touching pair.
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '11:59']],
            openTime: '12:00',
        );

        $validator = Validator::make(['close_time' => '16:00'], ['close_time' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_it_passes_with_no_existing_periods(): void
    {
        $rule = new NoOverlappingPeriod(existingPeriods: [], openTime: '08:00');

        $validator = Validator::make(['close_time' => '17:00'], ['close_time' => [$rule]]);

        $this->assertTrue($validator->passes());
    }
}

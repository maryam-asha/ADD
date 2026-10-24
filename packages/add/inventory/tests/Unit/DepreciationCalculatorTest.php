<?php

namespace Add\Inventory\Tests\Unit;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;

class DepreciationCalculatorTest extends TestCase
{
    private function asset(array $attributes): Asset
    {
        return new Asset([
            'purchased_on' => '2026-01-15',
            'purchase_cost' => '1200',
            'salvage_value' => '200',
            'useful_life_months' => 40,
            ...$attributes,
        ]);
    }

    public function test_straight_line_by_whole_months(): void
    {
        $figures = (new DepreciationCalculator)->for($this->asset([]), CarbonImmutable::parse('2026-11-14'));

        // 1000 spread over 40 months = 25 a month; 9 whole months passed.
        $this->assertSame([
            'monthly' => '25',
            'months_elapsed' => 9,
            'accumulated' => '225',
            'book_value' => '975',
            'fully_depreciated' => false,
        ], $figures);
    }

    public function test_it_stops_at_the_salvage_value(): void
    {
        $figures = (new DepreciationCalculator)->for($this->asset([]), CarbonImmutable::parse('2031-01-01'));

        $this->assertSame('1000', $figures['accumulated']);
        $this->assertSame('200', $figures['book_value']);
        $this->assertTrue($figures['fully_depreciated']);
    }

    public function test_it_stops_on_the_day_of_disposal(): void
    {
        $figures = (new DepreciationCalculator)->for($this->asset(['disposed_on' => '2026-03-20']), CarbonImmutable::parse('2027-01-01'));

        $this->assertSame(2, $figures['months_elapsed']);
        $this->assertSame('1150', $figures['book_value']);
    }

    public function test_nothing_without_cost_date_or_life(): void
    {
        $calculator = new DepreciationCalculator;

        $this->assertNull($calculator->for($this->asset(['purchase_cost' => null])));
        $this->assertNull($calculator->for($this->asset(['purchased_on' => null])));
        $this->assertNull($calculator->for($this->asset(['useful_life_months' => null])));
    }
}

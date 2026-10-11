<?php

namespace Tests\Unit\Domain\Leasing;

use App\Domain\Leasing\Services\LeaseScheduleBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LeaseScheduleBuilderTest extends TestCase
{
    private LeaseScheduleBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new LeaseScheduleBuilder;
    }

    public function test_a_quarterly_year_has_four_periods_ending_on_the_lease_end(): void
    {
        $periods = $this->builder->build('2026-01-01', 12, 3, '500.00');

        $this->assertSame([
            ['sequence' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'amount' => '1500.00'],
            ['sequence' => 2, 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30', 'amount' => '1500.00'],
            ['sequence' => 3, 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30', 'amount' => '1500.00'],
            ['sequence' => 4, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'amount' => '1500.00'],
        ], $periods);
        $this->assertSame('2026-12-31', $this->builder->endsOn('2026-01-01', 12));
    }

    public function test_a_monthly_lease_from_the_31st_never_sticks_on_the_28th(): void
    {
        $starts = array_column($this->builder->build('2026-01-31', 4, 1, '100.00'), 'starts_on');
        $ends = array_column($this->builder->build('2026-01-31', 4, 1, '100.00'), 'ends_on');

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $starts);
        $this->assertSame(['2026-02-27', '2026-03-30', '2026-04-29', '2026-05-30'], $ends);
        $this->assertSame('2026-05-30', $this->builder->endsOn('2026-01-31', 4));
    }

    public function test_a_leap_day_start(): void
    {
        $starts = array_column($this->builder->build('2028-02-29', 12, 6, '100.00'), 'starts_on');

        $this->assertSame(['2028-02-29', '2028-08-29'], $starts);
        $this->assertSame('2029-02-27', $this->builder->endsOn('2028-02-29', 12));
    }

    public function test_one_payment_for_the_whole_term(): void
    {
        $periods = $this->builder->build('2026-03-15', 6, 6, '333.33');

        $this->assertCount(1, $periods);
        $this->assertSame('1999.98', $periods[0]['amount']);
        $this->assertSame('2026-09-14', $periods[0]['ends_on']);
    }

    public function test_the_periods_add_up_to_the_monthly_amount_times_the_duration(): void
    {
        $total = array_reduce($this->builder->build('2026-01-01', 12, 1, '123.45'), fn ($sum, $p) => bcadd($sum, $p['amount'], 2), '0');

        $this->assertSame(bcmul('123.45', '12', 2), $total);
    }

    public function test_valid_cycles(): void
    {
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 1));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 3));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 6));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 12));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(7, 7));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(10, 3));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(12, 4));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(12, 0));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(0, 1));
    }

    public function test_an_invalid_cycle_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build('2026-01-01', 10, 3, '100.00');
    }
}

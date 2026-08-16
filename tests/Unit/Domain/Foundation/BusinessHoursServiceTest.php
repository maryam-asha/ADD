<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    private BusinessHoursService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BusinessHoursService(new SettingService);
    }

    public function test_a_weekday_with_no_schedule_rows_resolves_to_closed(): void
    {
        $branch = Branch::factory()->create();
        // Monday has no rows at all.
        $monday = Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'); // a Monday

        $this->assertFalse($this->service->isWithinBusinessHours($monday, $branch));
        $this->assertSame([], $this->service->periodsFor($monday, $branch));
    }

    public function test_an_instant_within_the_weekly_schedule_is_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_instant_exactly_at_open_time_is_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_instant_exactly_at_close_time_is_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 17:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_instant_one_minute_after_close_time_is_not_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 17:01:00', 'Asia/Damascus');

        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_two_period_day_treats_the_midday_gap_as_closed(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '15:00',
            'close_time' => '20:00',
        ]);

        $morning = Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus');
        $gap = Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus');
        $evening = Carbon::parse('2026-08-17 16:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($morning, $branch));
        $this->assertFalse($this->service->isWithinBusinessHours($gap, $branch));
        $this->assertTrue($this->service->isWithinBusinessHours($evening, $branch));
        $this->assertCount(2, $this->service->periodsFor($morning, $branch));
    }

    public function test_exception_overrides_the_weekday_schedule_for_that_date(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        // 2026-08-17 is a Monday — shortened hours just for this date.
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-08-17',
            'is_closed' => false,
            'open_time' => '10:00',
            'close_time' => '14:00',
        ]);

        $withinExceptionOnly = Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus');
        $withinBoth = Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus');

        // 15:00 is within the normal Monday schedule but NOT the exception.
        $this->assertFalse($this->service->isWithinBusinessHours($withinExceptionOnly, $branch));
        $this->assertTrue($this->service->isWithinBusinessHours($withinBoth, $branch));
    }

    public function test_closed_entirely_exception_blocks_a_day_that_is_normally_open(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        BusinessHourException::factory()->closedEntirely()->create([
            'branch_id' => $branch->id,
            'date' => '2026-08-17',
            'reason' => 'Emergency closure',
        ]);

        $instant = Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus');

        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branch));
        $this->assertSame([], $this->service->periodsFor($instant, $branch));
    }

    public function test_one_branchs_hours_do_not_leak_into_another_branchs_resolution(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branchA->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        // Branch B has no schedule at all.

        $instant = Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branchA));
        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branchB));
    }

    public function test_resolution_is_correct_for_an_instant_near_a_day_boundary_in_the_configured_timezone(): void
    {
        $branch = Branch::factory()->create();
        // Sunday is open all day; Monday has no schedule at all (closed).
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Sunday,
            'open_time' => '00:00',
            'close_time' => '23:59',
        ]);

        $settings = new SettingService;
        $settings->setDefault('app.timezone', 'Asia/Damascus', SettingValueType::String);

        // 2026-08-16 is a Sunday. 22:00:00 UTC, converted to Asia/Damascus
        // (UTC+3, no DST), is 2026-08-17 01:00:00 — already Monday locally,
        // even though the instant's own UTC calendar day is still Sunday.
        $instant = Carbon::parse('2026-08-16 22:00:00', 'UTC');

        // If resolution incorrectly used the instant's UTC weekday (Sunday,
        // open all day), this would wrongly return true. The correct,
        // timezone-aware answer is false: locally it is already Monday,
        // which has no schedule at all.
        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_resolution_uses_the_configured_timezone_setting_not_just_the_hardcoded_default(): void
    {
        $branch = Branch::factory()->create();
        // Sunday is open all day; Monday has no schedule at all (closed).
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Sunday,
            'open_time' => '00:00',
            'close_time' => '23:59',
        ]);

        $settings = new SettingService;
        $settings->setDefault('app.timezone', 'UTC', SettingValueType::String);

        // Same instant as the day-boundary test (2026-08-16 22:00 UTC), which
        // resolves to Monday (closed) under Asia/Damascus but is still Sunday
        // (open all day) under UTC. If the service ignored the configured
        // setting and always used its hardcoded 'Asia/Damascus' default, this
        // would incorrectly return false instead of true.
        $instant = Carbon::parse('2026-08-16 22:00:00', 'UTC');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }
}

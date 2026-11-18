<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHourTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_day_of_week_to_the_enum(): void
    {
        $branch = Branch::factory()->create();

        $businessHour = BusinessHour::create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $this->assertSame(DayOfWeek::Monday, $businessHour->fresh()->day_of_week);
    }

    public function test_it_belongs_to_a_branch(): void
    {
        $branch = Branch::factory()->create();
        $businessHour = BusinessHour::factory()->create(['branch_id' => $branch->id]);

        $this->assertTrue($businessHour->branch->is($branch));
    }

    public function test_branch_has_many_business_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->count(2)->create(['branch_id' => $branch->id]);

        $this->assertCount(2, $branch->fresh()->businessHours);
    }
}

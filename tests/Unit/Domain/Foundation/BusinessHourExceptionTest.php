<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHourExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_date_and_is_closed(): void
    {
        $branch = Branch::factory()->create();

        $exception = BusinessHourException::create([
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => true,
            'reason' => 'Holiday',
        ]);

        $fresh = $exception->fresh();
        $this->assertTrue($fresh->date->isSameDay('2026-12-25'));
        $this->assertTrue($fresh->is_closed);
    }

    public function test_it_belongs_to_a_branch(): void
    {
        $branch = Branch::factory()->create();
        $exception = BusinessHourException::factory()->create(['branch_id' => $branch->id]);

        $this->assertTrue($exception->branch->is($branch));
    }

    public function test_branch_has_many_business_hour_exceptions(): void
    {
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->count(2)->create(['branch_id' => $branch->id]);

        $this->assertCount(2, $branch->fresh()->businessHourExceptions);
    }
}

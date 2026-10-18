<?php

namespace Tests\Feature\Hr;

use Add\Hr\Timesheets\Models\CostCenter;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * ADD's timesheet week follows its working week: Sunday to Saturday.
 */
class HrTimesheetWeekTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    public function test_an_add_timesheet_week_starts_on_sunday(): void
    {
        $this->travelTo('2026-03-04 09:00:00');
        $user = User::factory()->create();
        $this->hireFor($user);
        $center = CostCenter::query()->create(['code' => 'ops', 'name' => ['ar' => 'التشغيل']]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/hr/me/timesheets/2026-03-02')->assertUnprocessable();
        $this->putJson('/api/v1/hr/me/timesheets/2026-03-01', ['entries' => [
            ['cost_center_id' => $center->id, 'work_date' => '2026-03-01', 'hours' => 8],
        ]])->assertOk();
        $this->getJson('/api/v1/hr/me/timesheets/2026-03-01')->assertOk()
            ->assertJsonPath('data.week_end', '2026-03-07')
            ->assertJsonPath('data.total_hours', '8');
    }
}

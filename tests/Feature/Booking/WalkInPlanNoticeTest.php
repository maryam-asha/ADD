<?php

namespace Tests\Feature\Booking;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanHourCoverageService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Starting a session outside the member's plan window is NOT refused — the
 * session starts as an ordinary paid walk-in and reception is told the plan
 * is not covering it (docs/decisions/plan-access-windows.md). A caveat on a
 * success, not an error.
 */
class WalkInPlanNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(ResourceTypeSeeder::class);
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['capacity' => 5, 'hourly_rate_points' => 100]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '00:00',
                'close_time' => '23:59',
            ]);
        }

        return $space;
    }

    private function memberOnEveningPlan(float $grantedHours = 40): User
    {
        $plan = Plan::factory()->evening()->create();
        $plan->resourceGrants()->create([
            'resource_type_id' => ResourceType::where('slug', PlanHourCoverageService::RESOURCE_TYPE_SLUG)->value('id'),
            'quantity' => $grantedHours,
            'order' => 0,
        ]);

        $member = User::factory()->create();

        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::parse('2026-08-01 00:00:00'),
            'current_period_end' => Carbon::parse('2026-12-01 00:00:00'),
        ]);

        return $member;
    }

    public function test_starting_inside_the_plan_window_reports_that_the_plan_applies(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Damascus'));

        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = $this->memberOnEveningPlan();

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.plan_applies', true);
        $response->assertJsonMissingPath('message');
    }

    public function test_starting_outside_the_plan_window_still_creates_the_session_with_a_notice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00', 'Asia/Damascus'));

        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = $this->memberOnEveningPlan();

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        // Created, not refused — that is the whole point.
        $response->assertCreated();
        $response->assertJsonPath('data.plan_applies', false);
        $response->assertJsonPath('message', __('api.reception.outside_plan_window'));

        $this->assertDatabaseHas('walkin_sessions', [
            'user_id' => $member->id,
            'space_id' => $space->id,
        ]);
    }

    public function test_the_session_records_the_membership_in_force_at_check_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Damascus'));

        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = $this->memberOnEveningPlan();

        $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ])->assertCreated();

        $this->assertDatabaseHas('walkin_sessions', [
            'user_id' => $member->id,
            'membership_id' => Membership::where('owner_id', $member->id)->value('id'),
        ]);
    }

    public function test_a_member_with_no_plan_gets_no_notice_at_all(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00', 'Asia/Damascus'));

        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = User::factory()->create();

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.plan_applies', false);
        $response->assertJsonMissingPath('message');
    }

    /**
     * A plan whose hours are already spent is, for this purpose, the same as
     * being outside the window: nothing will be covered, so say so up front
     * rather than at checkout.
     */
    public function test_a_plan_with_no_hours_left_reports_that_it_does_not_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Damascus'));

        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = $this->memberOnEveningPlan(grantedHours: 0);

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.plan_applies', false);
        $response->assertJsonPath('message', __('api.reception.outside_plan_window'));
    }
}

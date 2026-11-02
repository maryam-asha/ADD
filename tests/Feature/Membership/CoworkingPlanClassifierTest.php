<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\PlanModule;
use App\Domain\Membership\Models\PlanResourceGrant;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Existing plans are classified into the new coworking terms, with a report
 * to review before the conversion runs (product owner, 2026-11-02).
 */
class CoworkingPlanClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_04_100003_convert_plans_to_coworking_terms.php');
    }

    private function hoursGrant(Plan $plan, float $hours): PlanResourceGrant
    {
        return $plan->resourceGrants()->create([
            'resource_type_id' => ResourceType::where('slug', 'space-hours')->value('id'),
            'quantity' => $hours,
            'order' => 0,
        ]);
    }

    private function classify(Plan $plan): array
    {
        return app(CoworkingPlanClassifier::class)->classify($plan->fresh());
    }

    public function test_a_dedicated_desk_module_plan_becomes_dedicated(): void
    {
        $module = PlanModule::query()->create(['name' => ['en' => 'Desk'], 'slug' => PlanModule::DEDICATED_DESK]);
        $plan = Plan::factory()->create(['plan_module_id' => $module->id]);

        $this->assertSame(CoworkingSeat::Dedicated, $this->classify($plan)['seat']);
        $this->assertNull($this->classify($plan)['hours']);
    }

    public function test_a_dedicated_desk_plan_with_hours_drops_them_and_is_flagged(): void
    {
        $module = PlanModule::query()->create(['name' => ['en' => 'Desk'], 'slug' => PlanModule::DEDICATED_DESK]);
        $plan = Plan::factory()->create(['plan_module_id' => $module->id]);
        $this->hoursGrant($plan, 40);

        $result = $this->classify($plan);
        $this->assertSame(CoworkingSeat::Dedicated, $result['seat']);
        $this->assertNull($result['hours']);
        $this->assertContains('dedicated_with_hours', $result['flags']);
    }

    public function test_a_plan_with_space_hours_becomes_flexible_with_those_hours(): void
    {
        $plan = Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']);
        $this->hoursGrant($plan, 20);

        $result = $this->classify($plan);
        $this->assertSame(CoworkingSeat::Flexible, $result['seat']);
        $this->assertSame('20.00', $result['hours']);
        $this->assertSame([], $result['flags']);
    }

    /** The old catalog stood in for "unlimited" with every hour its window opens over the period. */
    public function test_a_grant_covering_every_window_hour_becomes_unlimited(): void
    {
        $allDay = Plan::factory()->create(['duration_days' => 30, 'access_window_start' => '00:00', 'access_window_end' => '00:00']);
        $this->hoursGrant($allDay, 720);
        $evening = Plan::factory()->create(['duration_days' => 30, 'access_window_start' => '17:00', 'access_window_end' => '00:00']);
        $this->hoursGrant($evening, 210);

        $this->assertSame(CoworkingSeat::Flexible, $this->classify($allDay)['seat']);
        $this->assertNull($this->classify($allDay)['hours']);
        $this->assertNull($this->classify($evening)['hours']);
    }

    public function test_any_other_plan_is_not_coworking(): void
    {
        $this->assertSame(CoworkingSeat::None, $this->classify(Plan::factory()->create())['seat']);
    }

    public function test_report_flags_hours_without_window(): void
    {
        $plan = Plan::factory()->create();
        $this->hoursGrant($plan, 20);

        $this->assertContains('hours_without_window', $this->classify($plan)['flags']);
    }

    public function test_report_flags_window_without_hours(): void
    {
        $plan = Plan::factory()->evening()->create();

        $result = $this->classify($plan);
        $this->assertSame(CoworkingSeat::None, $result['seat']);
        $this->assertContains('window_without_hours', $result['flags']);
    }

    public function test_the_report_command_prints_every_plan(): void
    {
        $plan = Plan::factory()->create();
        $this->hoursGrant($plan, 20);

        // One row: the seat and the flag sit on the same line.
        $this->artisan('plans:coworking-report')
            ->expectsOutputToContain('hours_without_window')
            ->assertSuccessful();
    }

    public function test_conversion_writes_the_terms_and_moves_consumed_hours_to_the_session_users_ledger(): void
    {
        $plan = Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']);
        $grant = $this->hoursGrant($plan, 20);
        $membership = Membership::factory()->create(['plan_id' => $plan->id]);
        $session = WalkinSession::factory()->create(['membership_id' => $membership->id]);
        $consumption = ResourceConsumption::query()->create([
            'plan_resource_grant_id' => $grant->id,
            'membership_id' => $membership->id,
            'quantity' => 2.5,
        ]);
        $consumption->sessionable()->associate($session)->save();

        $this->migration()->up();

        $plan->refresh();
        $this->assertSame(CoworkingSeat::Flexible, $plan->coworking_seat);
        $this->assertSame(20.0, (float) $plan->coworking_hours);
        $usage = PlanHourUsage::query()->sole();
        $this->assertSame($membership->id, $usage->membership_id);
        $this->assertSame($session->user_id, $usage->user_id);
        $this->assertNull($usage->plan_space_hour_id);
        $this->assertSame(9000, $usage->seconds);
        $this->assertTrue($usage->usable->is($session));
        $this->assertTrue($usage->cycle_start->equalTo($membership->current_period_start));
        $this->assertSame(0, ResourceConsumption::query()->count());
        $this->assertSame(0, $plan->resourceGrants()->count());
    }

    public function test_conversion_charges_an_unlinked_consumption_to_a_personal_owner(): void
    {
        $plan = Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']);
        $grant = $this->hoursGrant($plan, 20);
        $membership = Membership::factory()->create(['plan_id' => $plan->id]);
        ResourceConsumption::query()->create([
            'plan_resource_grant_id' => $grant->id,
            'membership_id' => $membership->id,
            'quantity' => 1,
        ]);

        $this->migration()->up();

        $this->assertSame($membership->owner_id, PlanHourUsage::query()->sole()->user_id);
    }
}

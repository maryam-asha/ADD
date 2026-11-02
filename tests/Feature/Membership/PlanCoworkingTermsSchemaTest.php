<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\PlanModule;
use App\Domain\Membership\Models\PlanSpaceHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** A plan states its coworking seat, hours and cycle, and can give hours on a space (product owner, 2026-11-02). */
class PlanCoworkingTermsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_defaults_to_no_coworking_and_a_whole_period_cycle(): void
    {
        $plan = Plan::query()->create([
            'name' => ['en' => 'Printing'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
        ])->refresh();

        $this->assertSame(CoworkingSeat::None, $plan->coworking_seat);
        $this->assertSame(AllowanceCycle::Period, $plan->allowance_cycle);
        $this->assertNull($plan->coworking_hours);
        $this->assertFalse($plan->includesCoworking());
    }

    public function test_a_flexible_plan_with_hours_includes_coworking(): void
    {
        $plan = Plan::factory()->create([
            'coworking_seat' => CoworkingSeat::Flexible,
            'coworking_hours' => 20,
            'allowance_cycle' => AllowanceCycle::Monthly,
        ])->refresh();

        $this->assertTrue($plan->includesCoworking());
        $this->assertSame(20.0, (float) $plan->coworking_hours);
        $this->assertSame(AllowanceCycle::Monthly, $plan->allowance_cycle);
    }

    public function test_is_dedicated_desk_follows_the_seat_not_the_module(): void
    {
        $module = PlanModule::query()->create(['name' => ['en' => 'Desk'], 'slug' => PlanModule::DEDICATED_DESK]);

        $this->assertFalse(Plan::factory()->create(['plan_module_id' => $module->id])->isDedicatedDesk());
        $this->assertTrue(Plan::factory()->create(['coworking_seat' => CoworkingSeat::Dedicated])->isDedicatedDesk());
    }

    public function test_space_hours_belong_to_a_plan(): void
    {
        $plan = Plan::factory()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::Room, 'hours' => 5]);

        $row = $plan->spaceHours()->sole();
        $this->assertSame(SpaceType::Room, $row->space_type);
        $this->assertSame(5.0, (float) $row->hours);
    }

    public function test_space_hours_refuse_a_space_and_a_type_at_once(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanSpaceHour::factory()->create([
            'space_id' => Space::factory()->create()->id,
            'space_type' => SpaceType::Room,
        ]);
    }

    public function test_an_hour_usage_links_its_session(): void
    {
        $membership = Membership::factory()->create();
        $session = WalkinSession::factory()->create();

        $usage = PlanHourUsage::factory()->create([
            'membership_id' => $membership->id,
            'user_id' => $session->user_id,
            'usable_type' => $session->getMorphClass(),
            'usable_id' => $session->id,
            'seconds' => 3600,
        ]);

        $this->assertTrue($usage->usable->is($session));
        $this->assertNull($usage->allowance);
    }
}

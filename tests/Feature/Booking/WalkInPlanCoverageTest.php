<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Splitting a walk-in's bill at its plan's access-window boundary
 * (docs/decisions/plan-access-windows.md). The hours inside the window are
 * drawn from the plan's granted quota; everything else is billed normally.
 */
class WalkInPlanCoverageTest extends TestCase
{
    use RefreshDatabase;

    private SessionClosureService $closures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->closures = app(SessionClosureService::class);
    }

    /**
     * Open around the clock, so business hours never mask what the plan
     * window is doing. This suite is about the plan window, not the branch's.
     */
    private function openSpace(int $hourlyRatePoints = 100): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => $hourlyRatePoints]);

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

    private function eveningPlanWithHours(float $grantedHours): Plan
    {
        $plan = Plan::factory()->evening()->create();

        $plan->resourceGrants()->create([
            'resource_type_id' => ResourceType::where('slug', PlanHourCoverageService::RESOURCE_TYPE_SLUG)->value('id'),
            'quantity' => $grantedHours,
            'order' => 0,
        ]);

        return $plan;
    }

    private function memberOn(Plan $plan): User
    {
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

    /** Local Asia/Damascus wall-clock to the UTC the columns actually store. */
    private function localToUtc(string $time): Carbon
    {
        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');

        return Carbon::parse($time, $timezone)->setTimezone('UTC');
    }

    private function sessionFor(User $member, Space $space, string $checkedInAtLocal): WalkinSession
    {
        return WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'membership_id' => Membership::where('owner_id', $member->id)->value('id'),
            'checked_in_at' => $this->localToUtc($checkedInAtLocal),
        ]);
    }

    public function test_a_session_fully_inside_the_plan_window_costs_nothing_and_settles_itself(): void
    {
        $space = $this->openSpace(100);
        $member = $this->memberOn($this->eveningPlanWithHours(40));
        $session = $this->sessionFor($member, $space, '2026-08-17 21:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 23:00'));

        $session->refresh();
        $this->assertSame('0.00', (string) $session->amount_owed);
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame(PaymentSource::Plan, $session->payment_source);
        $this->assertSame(2 * 3600, $session->plan_covered_seconds);
    }

    /**
     * The spec's own example: 19:00-22:00 against a 20:00-00:00 plan is one
     * paid hour plus two plan hours.
     */
    public function test_a_session_straddling_the_window_is_billed_only_for_the_hours_outside_it(): void
    {
        $space = $this->openSpace(100);
        $member = $this->memberOn($this->eveningPlanWithHours(40));
        $session = $this->sessionFor($member, $space, '2026-08-17 19:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 22:00'));

        $session->refresh();
        // One hour at 100 points/hour, converted at the seeded point rate.
        $this->assertSame('10.00', (string) $session->amount_owed);
        $this->assertSame(2 * 3600, $session->plan_covered_seconds);
        // Still owes money, so it is NOT auto-settled.
        $this->assertSame(PaymentState::Unpaid, $session->payment_state);
    }

    public function test_a_session_entirely_outside_the_window_is_billed_in_full(): void
    {
        $space = $this->openSpace(100);
        $member = $this->memberOn($this->eveningPlanWithHours(40));
        $session = $this->sessionFor($member, $space, '2026-08-17 14:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 16:00'));

        $session->refresh();
        $this->assertSame('20.00', (string) $session->amount_owed);
        $this->assertSame(0, $session->plan_covered_seconds);
        $this->assertSame(PaymentState::Unpaid, $session->payment_state);
    }

    /**
     * When the quota runs out mid-window the member pays cash for the rest,
     * deliberately NOT out of their general wallet balance.
     */
    public function test_exhausted_plan_hours_fall_through_to_a_normal_charge(): void
    {
        $space = $this->openSpace(100);
        $member = $this->memberOn($this->eveningPlanWithHours(1));
        $session = $this->sessionFor($member, $space, '2026-08-17 20:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 23:00'));

        $session->refresh();
        // One hour covered by the grant, two billed.
        $this->assertSame(3600, $session->plan_covered_seconds);
        $this->assertSame('20.00', (string) $session->amount_owed);
        $this->assertSame(PaymentState::Unpaid, $session->payment_state);
    }

    public function test_coverage_is_recorded_against_the_membership_quota(): void
    {
        $space = $this->openSpace(100);
        $plan = $this->eveningPlanWithHours(40);
        $member = $this->memberOn($plan);
        $session = $this->sessionFor($member, $space, '2026-08-17 21:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 23:00'));

        $membershipId = Membership::where('owner_id', $member->id)->value('id');
        $this->assertSame('2.00', (string) ResourceConsumption::where('membership_id', $membershipId)->value('quantity'));
    }

    public function test_a_second_session_sees_the_quota_the_first_one_consumed(): void
    {
        $space = $this->openSpace(100);
        $member = $this->memberOn($this->eveningPlanWithHours(3));

        $first = $this->sessionFor($member, $space, '2026-08-17 20:00');
        $this->closures->closeOut($first, $this->localToUtc('2026-08-17 22:00'));

        $second = $this->sessionFor($member, $space, '2026-08-18 20:00');
        $this->closures->closeOut($second, $this->localToUtc('2026-08-18 22:00'));

        // Only one hour of quota was left for the second session.
        $this->assertSame(2 * 3600, $first->refresh()->plan_covered_seconds);
        $this->assertSame(3600, $second->refresh()->plan_covered_seconds);
        $this->assertSame('10.00', (string) $second->amount_owed);
    }

    public function test_a_walk_in_with_no_membership_is_billed_exactly_as_before(): void
    {
        $space = $this->openSpace(100);
        $member = User::factory()->create();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'checked_in_at' => $this->localToUtc('2026-08-17 21:00'),
        ]);

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 23:00'));

        $session->refresh();
        $this->assertSame('20.00', (string) $session->amount_owed);
        $this->assertSame(0, $session->plan_covered_seconds);
        $this->assertNull($session->payment_source);
    }

    public function test_a_plan_without_an_access_window_covers_nothing(): void
    {
        $space = $this->openSpace(100);
        $plan = Plan::factory()->create(); // no window
        $plan->resourceGrants()->create([
            'resource_type_id' => ResourceType::where('slug', PlanHourCoverageService::RESOURCE_TYPE_SLUG)->value('id'),
            'quantity' => 40,
            'order' => 0,
        ]);
        $member = $this->memberOn($plan);
        $session = $this->sessionFor($member, $space, '2026-08-17 21:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 23:00'));

        $session->refresh();
        $this->assertSame('20.00', (string) $session->amount_owed);
        $this->assertSame(0, $session->plan_covered_seconds);
    }

    public function test_a_plan_with_a_window_but_no_hour_grant_covers_nothing(): void
    {
        $space = $this->openSpace(100);
        $member = $this->memberOn(Plan::factory()->evening()->create());
        $session = $this->sessionFor($member, $space, '2026-08-17 21:00');

        $this->closures->closeOut($session, $this->localToUtc('2026-08-17 23:00'));

        $session->refresh();
        $this->assertSame('20.00', (string) $session->amount_owed);
        $this->assertSame(0, $session->plan_covered_seconds);
    }
}

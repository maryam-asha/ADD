<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\PlanDiscountService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Discount uses are counted per person and per cycle, like hours, and a
 * coworking discount reaches walk-ins too (product owner, 2026-11-02).
 */
class PlanDiscountPerPersonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function membership(Plan $plan, User|Company $owner, string $start = '2026-08-07 00:00'): Membership
    {
        return Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => $owner instanceof Company ? OwnerType::Company : OwnerType::User,
            'owner_id' => $owner->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::parse($start, 'Asia/Damascus')->utc(),
            'current_period_end' => Carbon::parse($start, 'Asia/Damascus')->utc()->addDays(90),
        ]);
    }

    /** A redemption by $user at $at, as a priced booking leaves. */
    private function use(PlanDiscount $discount, Membership $m, User $user, string $at = '2026-08-17 09:00'): void
    {
        $booking = Booking::factory()->create(['user_id' => $user->id]);
        $redemption = PlanDiscountRedemption::query()->create([
            'plan_discount_id' => $discount->id,
            'membership_id' => $m->id,
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'points_discounted' => 10,
        ]);
        $redemption->forceFill(['created_at' => Carbon::parse($at, 'Asia/Damascus')->utc()])->save();
    }

    private function resolve(Membership $m, User $user, Space $space, string $at = '2026-08-17 10:00'): ?PlanDiscount
    {
        return app(PlanDiscountService::class)->resolveFor($m, $space, [], $user, Carbon::parse($at, 'Asia/Damascus')->utc());
    }

    public function test_a_limited_discount_on_a_company_plan_counts_each_employee_apart(): void
    {
        $room = Space::factory()->room()->create();
        $plan = Plan::factory()->notCoworking()->create();
        $discount = $plan->discounts()->create(['percentage' => 20, 'space_type' => SpaceType::Room, 'usage_limit' => 3]);
        $company = Company::factory()->create();
        $m = $this->membership($plan, $company);
        [$a, $b] = [User::factory()->create(), User::factory()->create()];

        foreach (range(1, 3) as $_) {
            $this->use($discount, $m, $a);
        }

        $this->assertNull($this->resolve($m, $a, $room));
        $this->assertTrue($this->resolve($m, $b, $room)?->is($discount));
    }

    public function test_a_monthly_plan_gives_its_uses_again_each_month(): void
    {
        $room = Space::factory()->room()->create();
        $plan = Plan::factory()->notCoworking()->create(['allowance_cycle' => AllowanceCycle::Monthly]);
        $discount = $plan->discounts()->create(['percentage' => 20, 'space_type' => SpaceType::Room, 'usage_limit' => 1]);
        $member = User::factory()->create();
        $m = $this->membership($plan, $member, '2026-07-15 00:00');
        $this->use($discount, $m, $member, '2026-07-20 10:00');

        $this->assertNull($this->resolve($m, $member, $room, '2026-08-10 10:00'));
        $this->assertTrue($this->resolve($m, $member, $room, '2026-08-16 10:00')?->is($discount));
    }

    public function test_a_coworking_discount_prices_a_walk_in_and_is_recorded_against_it(): void
    {
        $space = Space::factory()->create(['space_type' => SpaceType::CoSpace, 'hourly_rate_points' => 100]);
        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => $day, 'open_time' => '08:00', 'close_time' => '20:00']);
        }
        $plan = Plan::factory()->notCoworking()->create();
        $discount = $plan->discounts()->create(['percentage' => 10, 'space_type' => SpaceType::CoSpace]);
        $member = User::factory()->create();
        $m = $this->membership($plan, $member);
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'membership_id' => $m->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00', 'Asia/Damascus')->utc(),
        ]);

        app(SessionClosureService::class)->closeOut($session, now());

        $this->assertSame(180, (int) $session->refresh()->price_points);
        $redemption = PlanDiscountRedemption::query()->sole();
        $this->assertTrue($redemption->discount->is($discount));
        $this->assertSame($session->id, $redemption->walkin_session_id);
        $this->assertNull($redemption->booking_id);
        $this->assertSame($member->id, $redemption->user_id);
    }

    public function test_voiding_a_walk_in_drops_its_redemption(): void
    {
        $plan = Plan::factory()->notCoworking()->create();
        $discount = $plan->discounts()->create(['percentage' => 10]);
        $member = User::factory()->create();
        $m = $this->membership($plan, $member);
        $session = WalkinSession::factory()->create(['user_id' => $member->id]);
        app(PlanDiscountService::class)->redeem($discount, $m, $session, 5);

        app(PlanDiscountService::class)->voidFor($session);

        $this->assertSame(0, PlanDiscountRedemption::query()->count());
    }
}

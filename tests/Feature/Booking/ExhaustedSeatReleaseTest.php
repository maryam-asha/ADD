<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\PlanAllowanceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A flexible subscriber whose hours are spent gives the seat back until
 * their cycle renews; a dedicated desk never does (product owner, 2026-11-02).
 */
class ExhaustedSeatReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Space $space;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc());
        $this->space = Space::factory()->create(['space_type' => SpaceType::CoSpace, 'is_lockable' => false, 'capacity' => 1, 'hourly_rate_points' => 100]);
        $this->branch = $this->space->building->branch;
        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create(['branch_id' => $this->branch->id, 'day_of_week' => $day, 'open_time' => '08:00', 'close_time' => '22:00']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribe(User|Company $owner, Plan $plan, int $seats = 1, string $start = '2026-08-07 00:00'): Membership
    {
        return Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => $owner instanceof Company ? OwnerType::Company : OwnerType::User,
            'owner_id' => $owner->id,
            'branch_id' => $this->branch->id,
            'seats' => $seats,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::parse($start, 'Asia/Damascus')->utc(),
            'current_period_end' => Carbon::parse($start, 'Asia/Damascus')->utc()->addDays(90),
        ]);
    }

    private function spendAll(Membership $m, User $user, int $seconds): void
    {
        $allowances = app(PlanAllowanceService::class);
        $allowances->record($m, $user, $allowances->allowanceFor($m, SpaceType::CoSpace), WalkinSession::factory()->create(['user_id' => $user->id]), $seconds, now());
    }

    private function freeNow(): int
    {
        return app(CoworkingPoolService::class)->freeSeats($this->branch, CarbonImmutable::now(), CarbonImmutable::now()->addHour());
    }

    public function test_a_spent_flexible_subscription_frees_its_seat(): void
    {
        $member = User::factory()->create();
        $m = $this->subscribe($member, Plan::factory()->coworking(2)->create());
        $this->assertSame(0, $this->freeNow());

        $this->spendAll($m, $member, 7200);

        $this->assertSame(1, $this->freeNow());
    }

    public function test_an_exhausted_subscriber_needs_a_free_seat(): void
    {
        $member = User::factory()->create();
        $m = $this->subscribe($member, Plan::factory()->coworking(2)->create());
        $this->spendAll($m, $member, 7200);
        WalkinSession::factory()->create(['space_id' => $this->space->id, 'checked_in_at' => now()]);

        try {
            app(WalkInCapacityService::class)->start($this->space, $member);
            $this->fail('An exhausted subscriber took a seat that was not free');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.no_capacity', $e->messageKey);
        }
    }

    public function test_a_company_with_one_employee_spent_holds_one_seat_less(): void
    {
        $this->space->update(['capacity' => 3]);
        $company = Company::factory()->create();
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $company->members()->attach([$a->id, $b->id]);
        $m = $this->subscribe($company, Plan::factory()->coworking(2)->create(), seats: 3);
        $this->assertSame(0, $this->freeNow());

        $this->spendAll($m, $a, 7200);

        $this->assertSame(1, $this->freeNow());
    }

    /** Review I1: shared seats stay held while enough employees still have hours. */
    public function test_a_company_keeps_its_seats_while_more_employees_than_seats_have_hours(): void
    {
        $this->space->update(['capacity' => 2]);
        $company = Company::factory()->create();
        $people = User::factory()->count(10)->create();
        $company->members()->attach($people->modelKeys());
        $m = $this->subscribe($company, Plan::factory()->coworking(2)->create(), seats: 2);

        $this->spendAll($m, $people[0], 7200);
        $this->spendAll($m, $people[1], 7200);

        $this->assertSame(0, $this->freeNow());
    }

    public function test_a_dedicated_desk_holds_its_seat_whatever_was_used(): void
    {
        $member = User::factory()->create();
        $m = $this->subscribe($member, Plan::factory()->create(['coworking_seat' => CoworkingSeat::Dedicated]));
        $this->spendAll($m, $member, 100 * 3600);

        $this->assertSame(0, $this->freeNow());
    }

    public function test_seat_returns_when_the_cycle_renews(): void
    {
        $member = User::factory()->create();
        $m = $this->subscribe($member, Plan::factory()->coworking(2)->create(['allowance_cycle' => AllowanceCycle::Monthly]), start: '2026-07-20 00:00');
        $this->spendAll($m, $member, 7200);
        $this->assertSame(1, $this->freeNow());

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00', 'Asia/Damascus')->utc());

        $this->assertSame(0, $this->freeNow());
    }

    public function test_an_exhausted_subscriber_may_book_coworking_inside_the_window(): void
    {
        $this->space->update(['capacity' => 2]);
        $member = User::factory()->create();
        $m = $this->subscribe($member, Plan::factory()->coworking(2)->create(['access_window_start' => '09:00', 'access_window_end' => '17:00']));
        $this->spendAll($m, $member, 7200);

        $booking = app(BookingCreationService::class)->create(
            $this->space,
            $member,
            Carbon::parse('2026-08-18 10:00', 'Asia/Damascus')->utc(),
            Carbon::parse('2026-08-18 12:00', 'Asia/Damascus')->utc(),
        );

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame(200, (int) $booking->price_points);
    }
}

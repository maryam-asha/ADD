<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branch's co-working seat pool, gathered from the database: the seats
 * its co_spaces and lent rooms open, and the seats subscriptions hold and
 * bookings and walk-ins take (docs/decisions/coworking-seat-pool.md).
 */
class CoworkingPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00', 'Asia/Damascus'));
        $this->branch = Branch::factory()->create();
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time, string $date = '2026-10-01'): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", 'Asia/Damascus')->utc();
    }

    private function coSpace(?int $capacity, array $attributes = []): Space
    {
        return Space::factory()->create(['building_id' => $this->building->id, 'capacity' => $capacity, ...$attributes]);
    }

    private function pool(): CoworkingPoolService
    {
        return app(CoworkingPoolService::class);
    }

    private function subscribe(User|Company $owner, array $attributes = [], ?Plan $plan = null): Membership
    {
        return Membership::factory()->create([
            'plan_id' => ($plan ?? Plan::factory()->create())->id,
            'owner_type' => $owner instanceof Company ? OwnerType::Company : OwnerType::User,
            'owner_id' => $owner->id,
            'branch_id' => $this->branch->id,
            ...$attributes,
        ]);
    }

    private function book(User $user, Space $space, string $from, string $to): Booking
    {
        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'start_at' => $this->at($from),
            'end_at' => $this->at($to),
        ]);
    }

    public function test_the_pool_is_the_branchs_active_co_spaces(): void
    {
        $this->coSpace(10);
        $this->coSpace(6);
        $this->coSpace(null);
        $this->coSpace(8, ['status' => OperationalStatus::Maintenance]);
        Space::factory()->create(['capacity' => 50]);
        Space::factory()->room()->create(['building_id' => $this->building->id, 'capacity' => 12]);

        $timeline = $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('20:00'));

        $this->assertSame(16, $timeline->capacityAt($this->at('10:00')), 'no capacity is no seats; maintenance, rooms and other branches do not count');
    }

    public function test_a_room_lent_to_co_working_adds_its_seats_while_it_lasts(): void
    {
        $this->coSpace(10);
        $room = Space::factory()->room()->create(['building_id' => $this->building->id, 'capacity' => 8]);
        SpaceCoworkingWindow::factory()->create([
            'space_id' => $room->id, 'starts_at' => $this->at('14:00'), 'ends_at' => $this->at('18:00'), 'seats' => 6,
        ]);

        $timeline = $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('20:00'));

        $this->assertSame(10, $timeline->capacityAt($this->at('13:00')));
        $this->assertSame(16, $timeline->capacityAt($this->at('15:00')));
    }

    public function test_every_active_subscription_in_the_branch_holds_its_seats(): void
    {
        $this->coSpace(10);
        $this->subscribe(User::factory()->create());
        $this->subscribe(Company::factory()->create(['branch_id' => $this->branch->id]), ['seats' => 3]);
        $this->subscribe(User::factory()->create(), ['branch_id' => Branch::factory()->create()->id]);
        $this->subscribe(User::factory()->create(), ['current_period_end' => now()->subDay()]);

        $this->assertSame(6, $this->pool()->freeSeats($this->branch, $this->at('09:00'), $this->at('20:00')));
    }

    public function test_a_plan_with_hours_holds_its_seat_only_in_those_hours(): void
    {
        $this->coSpace(10);
        $this->subscribe(User::factory()->create(), plan: Plan::factory()->evening()->create());

        $timeline = $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('23:00'));

        $this->assertSame(0, $timeline->usageAt($this->at('12:00')));
        $this->assertSame(1, $timeline->usageAt($this->at('21:00')));
    }

    public function test_a_booking_takes_a_seat_unless_its_member_already_holds_one(): void
    {
        $space = $this->coSpace(10);
        $subscriber = User::factory()->create();
        $this->subscribe($subscriber);
        $this->book($subscriber, $space, '10:00', '12:00');
        $this->book(User::factory()->create(), $space, '11:00', '13:00');

        $timeline = $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('20:00'));

        $this->assertSame(1, $timeline->usageAt($this->at('10:30')));
        $this->assertSame(2, $timeline->usageAt($this->at('11:30')));
    }

    /** A company's two seats cover two of its people; the third takes a pool seat. */
    public function test_a_companys_people_beyond_its_seats_take_pool_seats(): void
    {
        $space = $this->coSpace(10);
        $company = Company::factory()->create(['branch_id' => $this->branch->id]);
        $this->subscribe($company, ['seats' => 2]);

        foreach (range(1, 3) as $ignored) {
            $employee = User::factory()->create();
            $company->members()->attach($employee->id);
            $this->book($employee, $space, '10:00', '12:00');
        }

        $this->assertSame(3, $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('20:00'))->usageAt($this->at('11:00')));
    }

    public function test_an_open_walk_in_takes_a_seat_now_and_is_not_projected_ahead(): void
    {
        $space = $this->coSpace(10);
        WalkinSession::factory()->create(['space_id' => $space->id, 'checked_in_at' => now()->subHour()]);
        WalkinSession::factory()->create(['space_id' => $space->id, 'checked_in_at' => now()->subHours(2), 'checked_out_at' => now()->subHour()]);

        $timeline = $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('20:00'));

        $this->assertSame(1, $timeline->usageAt(now()));
        $this->assertSame(0, $timeline->usageAt($this->at('12:00')));
    }

    /** The seats a new subscription would hold, tried before it is sold. */
    public function test_a_candidate_subscription_is_counted_over_its_hours(): void
    {
        $this->coSpace(2);
        $this->subscribe(User::factory()->create());

        $candidate = PoolDemand::subscription(Plan::factory()->evening()->create(), 2, $this->at('09:00'), $this->at('09:00', '2026-10-31'));
        $timeline = $this->pool()->timeline($this->branch, $this->at('09:00'), $this->at('09:00', '2026-10-31'), $candidate);

        $this->assertSame(1, $timeline->usageAt($this->at('12:00')));
        $this->assertSame(3, $timeline->usageAt($this->at('21:00')));
        $this->assertEquals($this->at('20:00'), $timeline->firstShortfall());
    }

    /** A new booking, counted as its member's: a subscriber's own adds nothing. */
    public function test_a_candidate_booking_is_counted_on_its_members_subscription(): void
    {
        $this->coSpace(1);
        $subscriber = User::factory()->create();
        $this->subscribe($subscriber);

        $mine = $this->pool()->timeline($this->branch, $this->at('10:00'), $this->at('11:00'), PoolDemand::presence($this->at('10:00'), $this->at('11:00'), $subscriber));
        $theirs = $this->pool()->timeline($this->branch, $this->at('10:00'), $this->at('11:00'), PoolDemand::presence($this->at('10:00'), $this->at('11:00'), User::factory()->create()));

        $this->assertSame(0, $mine->minFree());
        $this->assertSame(-1, $theirs->minFree());
    }

    /** An extension is tried without the booking it replaces. */
    public function test_a_candidate_can_stand_in_for_an_existing_booking(): void
    {
        $space = $this->coSpace(1);
        $booking = $this->book(User::factory()->create(), $space, '10:00', '11:00');

        $timeline = $this->pool()->timeline(
            $this->branch,
            $this->at('10:00'),
            $this->at('12:00'),
            PoolDemand::presence($this->at('10:00'), $this->at('12:00'), $booking->user, ignoreBookingId: $booking->id),
        );

        $this->assertSame(0, $timeline->minFree());
    }
}

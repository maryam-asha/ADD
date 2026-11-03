<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Pricing\Services\PriceQuoteService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a booking settles its plan hours once — on the stretch actually
 * billed — and plan hours cover an overstay (product owner, 2026-11-02).
 */
class BookingClosureCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow($this->local('07:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}", 'Asia/Damascus')->utc();
    }

    private function coSpace(): Space
    {
        $space = Space::factory()->create(['space_type' => SpaceType::CoSpace, 'is_lockable' => false, 'hourly_rate_points' => 100, 'capacity' => 10]);
        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => $day, 'open_time' => '08:00', 'close_time' => '22:00']);
        }

        return $space;
    }

    private function subscriber(Plan $plan): User
    {
        $member = User::factory()->create();
        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);

        return $member;
    }

    private function price(Space $space, string $from, string $to): int
    {
        return app(PriceQuoteService::class)->forBooking($space, $this->local($from), $this->local($to), null)->finalPoints;
    }

    private function spent(Booking $booking): int
    {
        return (int) PlanHourUsage::query()->where('usable_id', $booking->id)->where('usable_type', $booking->getMorphClass())->sum('seconds');
    }

    private function closeAt(Booking $booking, string $checkIn, string $checkOut): Booking
    {
        $booking->forceFill(['checked_in_at' => $this->local($checkIn)])->save();
        Carbon::setTestNow($this->local($checkOut));
        app(SessionClosureService::class)->closeOut($booking->refresh(), now());

        return $booking->refresh();
    }

    public function test_unpaid_booking_coverage_is_settled_once_at_closure(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber(Plan::factory()->coworking(20)->create(['access_window_start' => '09:00', 'access_window_end' => '17:00']));
        $booking = app(BookingCreationService::class)->create($space, $member, $this->local('16:00'), $this->local('19:00'));
        $this->assertSame(3600, $this->spent($booking));

        // Came at 16:30 (arrival billing): the plan covers 16:30–17:00 only.
        $booking = $this->closeAt($booking, '16:30', '19:00');

        $this->assertSame(1800, $this->spent($booking));
        $this->assertSame($this->price($space, '16:30', '18:30'), (int) $booking->price_points);
    }

    /** Review (minor 3): a booking crossing a monthly cycle boundary closes, its hours counted where they were used. */
    public function test_a_booking_crossing_a_cycle_boundary_closes_and_counts_its_hours_in_the_new_cycle(): void
    {
        $space = $this->coSpace();
        $plan = Plan::factory()->coworking(1)->create(['allowance_cycle' => AllowanceCycle::Monthly]);
        $member = User::factory()->create();
        $membership = Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::parse('2026-07-17 10:30', 'Asia/Damascus')->utc(),
            'current_period_end' => Carbon::parse('2026-07-17 10:30', 'Asia/Damascus')->utc()->addDays(90),
        ]);
        // The cycle ending 17 Aug 10:30 is spent.
        $allowances = app(PlanAllowanceService::class);
        $allowances->record($membership, $member, $allowances->allowanceFor($membership, SpaceType::CoSpace), WalkinSession::factory()->create(['user_id' => $member->id]), 3600, $this->local('09:00'));
        $booking = app(BookingCreationService::class)->create($space, $member, $this->local('10:00'), $this->local('12:00'));

        $booking = $this->closeAt($booking, '10:45', '12:00');

        $this->assertNotNull($booking->checked_out_at);
        $usage = PlanHourUsage::query()->where('usable_id', $booking->id)->where('usable_type', $booking->getMorphClass())->sole();
        $this->assertSame(3600, $usage->seconds);
        $this->assertTrue($usage->cycle_start->equalTo($this->local('10:30')));
    }

    public function test_a_paid_booking_overstaying_into_the_plan_window_owes_nothing_more(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber(Plan::factory()->coworking(20)->create(['access_window_start' => '16:00', 'access_window_end' => '22:00']));
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Paid,
            'price_points' => 200,
            'start_at' => $this->local('14:00'),
            'end_at' => $this->local('16:00'),
        ]);
        Payment::factory()->forPayable($booking)->create(['purpose' => PaymentPurpose::Booking, 'status' => PaymentStatus::Accepted, 'settled_at' => now(), 'amount_points' => 200, 'user_id' => $member->id]);

        $booking = $this->closeAt($booking, '14:00', '17:00');

        $this->assertSame(200, (int) $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(3600, $this->spent($booking));
    }

    public function test_a_paid_overstay_beyond_the_hours_left_is_billed(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber(Plan::factory()->coworking(1)->create());
        $booking = app(BookingCreationService::class)->create($space, $member, $this->local('14:00'), $this->local('16:00'));
        // The hour was spent on the booking itself; pay the other hour.
        $booking->forceFill(['payment_state' => PaymentState::Paid])->save();
        Payment::factory()->forPayable($booking)->create(['purpose' => PaymentPurpose::Booking, 'status' => PaymentStatus::Accepted, 'settled_at' => now(), 'amount_points' => (int) $booking->price_points, 'user_id' => $member->id]);
        $paid = (int) $booking->price_points;

        $booking = $this->closeAt($booking, '14:00', '17:00');

        $this->assertSame($paid + $this->price($space, '16:00', '17:00'), (int) $booking->price_points);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
    }

    public function test_an_unpaid_booking_the_plan_covers_entirely_at_closure_is_settled_by_the_plan(): void
    {
        $space = $this->coSpace();
        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::CoSpace, 'hours' => 5]);
        $member = $this->subscriber($plan);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 200,
            'start_at' => $this->local('10:00'),
            'end_at' => $this->local('12:00'),
        ]);

        $booking = $this->closeAt($booking, '10:00', '12:00');

        $this->assertSame(0, (int) $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(7200, $this->spent($booking));
    }
}

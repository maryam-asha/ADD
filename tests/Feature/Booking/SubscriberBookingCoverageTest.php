<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\BatchUnavailableException;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingBatchCreationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Pricing\Services\PriceQuoteService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A subscriber's own seat refuses a coworking booking it already covers;
 * plan hours price the rest of any booking (product owner, 2026-11-02).
 */
class SubscriberBookingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private BookingCreationService $creations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->creations = app(BookingCreationService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 07:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $time, string $date = '2026-08-17'): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'Asia/Damascus')->utc();
    }

    private function open(Space $space): Space
    {
        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => $day, 'open_time' => '08:00', 'close_time' => '22:00']);
        }

        return $space;
    }

    private function coSpace(): Space
    {
        return $this->open(Space::factory()->create(['space_type' => SpaceType::CoSpace, 'is_lockable' => false, 'hourly_rate_points' => 100, 'capacity' => 10]));
    }

    private function room(): Space
    {
        return $this->open(Space::factory()->room()->create(['hourly_rate_points' => 100]));
    }

    private function subscriber(Plan $plan): User
    {
        $member = User::factory()->create();
        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => $this->local('00:00', '2026-08-01'),
            'current_period_end' => $this->local('00:00', '2026-08-31'),
        ]);

        return $member;
    }

    private function windowPlan(?float $hours = 20): Plan
    {
        return Plan::factory()->coworking($hours)->create(['access_window_start' => '09:00', 'access_window_end' => '17:00']);
    }

    private function priceOf(Space $space, string $from, string $to): int
    {
        return app(PriceQuoteService::class)->forBooking($space, $this->local($from), $this->local($to), null)->finalPoints;
    }

    private function assertRefused(callable $book): void
    {
        try {
            $book();
            $this->fail('A covered booking was made');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.covered_by_plan', $e->messageKey);
        }
    }

    public function test_a_booking_inside_the_plan_window_is_refused(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber($this->windowPlan());

        $this->assertRefused(fn () => $this->creations->create($space, $member, $this->local('10:00'), $this->local('12:00')));
        $this->assertSame(0, Booking::count());
    }

    public function test_a_booking_overlapping_the_window_end_pays_only_the_outside_hours(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber($this->windowPlan());

        $booking = $this->creations->create($space, $member, $this->local('16:00'), $this->local('19:00'));

        $this->assertSame($this->priceOf($space, '16:00', '18:00'), (int) $booking->price_points);
        $this->assertSame(3600, (int) PlanHourUsage::query()->where('usable_id', $booking->id)->sum('seconds'));
    }

    public function test_a_booking_after_the_plan_ends_is_ordinary(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber($this->windowPlan());

        $booking = $this->creations->create($space, $member, $this->local('10:00', '2026-09-02'), $this->local('12:00', '2026-09-02'));

        $this->assertSame(200, (int) $booking->price_points);
        $this->assertSame(0, PlanHourUsage::count());
    }

    public function test_a_booking_after_hours_run_out_is_ordinary(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber($this->windowPlan(1));
        $first = $this->creations->create($space, $member, $this->local('16:00'), $this->local('18:00'));

        $second = $this->creations->create($space, $member, $this->local('10:00', '2026-08-18'), $this->local('12:00', '2026-08-18'));

        $this->assertSame(100, (int) $first->price_points);
        $this->assertSame(200, (int) $second->price_points);
    }

    public function test_a_dedicated_subscriber_is_refused_inside_the_window(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber(Plan::factory()->create(['coworking_seat' => CoworkingSeat::Dedicated, 'access_window_start' => '09:00', 'access_window_end' => '17:00']));

        $this->assertRefused(fn () => $this->creations->create($space, $member, $this->local('10:00'), $this->local('11:00')));
    }

    public function test_a_room_booking_uses_the_rooms_free_hours(): void
    {
        $room = $this->room();
        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::Room, 'hours' => 5]);
        $member = $this->subscriber($plan);

        $booking = $this->creations->create($room, $member, $this->local('10:00'), $this->local('13:00'));

        $this->assertSame(0, (int) $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Plan, $booking->payment_source);
        $this->assertSame(10800, (int) PlanHourUsage::query()->sum('seconds'));
    }

    public function test_a_non_coworking_plan_with_coworking_hours_can_book_and_spends_them(): void
    {
        $space = $this->coSpace();
        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::CoSpace, 'hours' => 3]);
        $member = $this->subscriber($plan);

        $booking = $this->creations->create($space, $member, $this->local('10:00'), $this->local('14:00'));

        $this->assertSame($this->priceOf($space, '10:00', '11:00'), (int) $booking->price_points);
        $this->assertSame(10800, (int) PlanHourUsage::query()->sum('seconds'));
    }

    public function test_a_batch_cannot_spend_the_same_hours_twice(): void
    {
        $room = $this->room();
        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::Room, 'hours' => 3]);
        $member = $this->subscriber($plan);

        $bookings = app(BookingBatchCreationService::class)->createMany([
            ['space_id' => $room->id, 'start_at' => $this->local('10:00', '2026-08-18'), 'end_at' => $this->local('12:00', '2026-08-18')],
            ['space_id' => $room->id, 'start_at' => $this->local('10:00', '2026-08-19'), 'end_at' => $this->local('12:00', '2026-08-19')],
        ], $member);

        $this->assertSame(10800, (int) PlanHourUsage::query()->sum('seconds'));
        $this->assertSame(100, collect($bookings)->sum(fn (Booking $b) => (int) $b->price_points));
    }

    public function test_a_batch_refuses_a_date_the_seat_covers(): void
    {
        $space = $this->coSpace();
        $member = $this->subscriber($this->windowPlan());

        $this->expectException(BatchUnavailableException::class);

        app(BookingBatchCreationService::class)->createMany([
            ['space_id' => $space->id, 'start_at' => $this->local('10:00', '2026-08-18'), 'end_at' => $this->local('12:00', '2026-08-18')],
        ], $member);
    }
}

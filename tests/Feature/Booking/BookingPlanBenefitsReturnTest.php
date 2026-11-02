<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booking that stops holding a charge gives back the plan hours it took,
 * with its discount use (product owner, 2026-11-02).
 */
class BookingPlanBenefitsReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 07:00', 'Asia/Damascus')->utc());

        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::Room, 'hours' => 2]);
        $plan->spaceHours()->create(['space_type' => SpaceType::CoSpace, 'hours' => 1]);
        $this->member = User::factory()->create();
        $this->membership = Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $time, string $date = '2026-08-18'): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'Asia/Damascus')->utc();
    }

    private function space(SpaceType $type, array $attributes = []): Space
    {
        $space = Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100, 'is_lockable' => false, 'capacity' => 10, ...$attributes]);
        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => $day, 'open_time' => '08:00', 'close_time' => '22:00']);
        }

        return $space;
    }

    private function book(Space $space, string $from = '10:00', string $to = '13:00', string $date = '2026-08-18'): Booking
    {
        return app(BookingCreationService::class)->create($space, $this->member, $this->local($from, $date), $this->local($to, $date));
    }

    private function spent(): int
    {
        return (int) PlanHourUsage::query()->sum('seconds');
    }

    public function test_a_member_cancelling_gives_the_hours_back(): void
    {
        $booking = $this->book($this->space(SpaceType::Room));
        $this->assertSame(7200, $this->spent());

        app(BookingCancellationService::class)->cancel($booking);

        $this->assertSame(0, $this->spent());
    }

    public function test_a_rejection_gives_the_hours_back(): void
    {
        $booking = $this->book($this->space(SpaceType::Room, ['requires_approval' => true]));
        $this->assertSame(BookingStatus::Pending, $booking->status);

        app(BookingApprovalService::class)->reject($booking, User::factory()->create(), 'No room that day');

        $this->assertSame(0, $this->spent());
    }

    public function test_a_lapse_gives_the_hours_back(): void
    {
        $booking = $this->book($this->space(SpaceType::Room, ['requires_approval' => true]));
        Carbon::setTestNow($this->local('10:00'));

        app(BookingApprovalService::class)->lapse($booking);

        $this->assertSame(0, $this->spent());
    }

    public function test_the_unpaid_release_gives_partly_covered_hours_back(): void
    {
        $booking = $this->book($this->space(SpaceType::CoSpace), '10:00', '14:00');
        $this->assertSame(3600, $this->spent());

        Carbon::setTestNow($this->local('11:00'));
        $this->artisan('bookings:release-unpaid-no-shows')->assertSuccessful();

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertSame(0, $this->spent());
    }

    public function test_a_fully_covered_no_show_keeps_the_hours_spent(): void
    {
        $booking = $this->book($this->space(SpaceType::Room), '10:00', '12:00');

        Carbon::setTestNow($this->local('12:05'));
        $this->artisan('bookings:close-elapsed')->assertSuccessful();

        $this->assertSame(BookingStatus::NoShow, $booking->refresh()->status);
        $this->assertSame(7200, $this->spent());
    }

    public function test_cancelling_after_the_plan_ended_returns_hours_to_the_old_membership(): void
    {
        $booking = $this->book($this->space(SpaceType::Room), '10:00', '12:00', '2026-08-25');
        $this->membership->forceFill(['current_period_end' => now()->subMinute()])->save();

        app(BookingCancellationService::class)->cancel($booking);

        $this->assertSame(0, PlanHourUsage::query()->where('membership_id', $this->membership->id)->count());
    }
}

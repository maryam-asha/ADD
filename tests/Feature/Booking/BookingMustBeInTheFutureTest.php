<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closes the trap documented in
 * docs/decisions/booking-creation-approval-extension.md: a past-dated
 * booking could be created and paid for, and BookingCancellationService
 * then refused to cancel it because its cancellation window had already
 * passed — so it could never be undone.
 */
class BookingMustBeInTheFutureTest extends TestCase
{
    use RefreshDatabase;

    private BookingCreationService $creations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->creations = app(BookingCreationService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ]);

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function slot(int $hour): array
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return [$start, $start->copy()->addHour()];
    }

    public function test_a_booking_starting_in_the_past_is_rejected(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(8);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for a past start.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.start_in_the_past', $e->messageKey);
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_a_booking_starting_at_this_very_moment_is_accepted(): void
    {
        // now() is exactly 10:00. Only a start strictly before now is
        // refused, matching the calendar, where a slot beginning this
        // instant is still offered as available.
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
    }

    public function test_a_future_booking_is_unaffected(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(14);

        $this->assertInstanceOf(
            Booking::class,
            $this->creations->create($space, User::factory()->create(), $start, $end)
        );
    }

    /**
     * Ordering matters for the message the member sees: a 6am booking on a
     * day that opens at 8am is both in the past and outside hours, and
     * "already passed" is the more useful of the two.
     */
    public function test_the_past_check_runs_before_the_business_hours_check(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(6);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.start_in_the_past', $e->messageKey);
        }
    }

    public function test_the_setting_allows_back_dating_when_an_operator_needs_it(): void
    {
        app(SettingService::class)->set('booking.allow_past_bookings', true);

        $space = $this->openSpace();
        [$start, $end] = $this->slot(8);

        $this->assertInstanceOf(
            Booking::class,
            $this->creations->create($space, User::factory()->create(), $start, $end)
        );
    }
}

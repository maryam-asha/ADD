<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Events\BookingReady;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * bookings:announce-ready and bookings:close-ended-rooms: a room booking
 * tells the app once when it can be checked in, and a checked-in one closes
 * itself at its end — its extended end, once extended — at its booked price.
 */
class RoomBookingScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow($this->at('09:00'));
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
    }

    /** A room booking at 100 points an hour, 10:15–12:15 unless told otherwise. */
    private function booking(array $attributes = [], SpaceType $type = SpaceType::Room): Booking
    {
        $space = Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $this->member->id,
            'start_at' => $this->at('10:15'),
            'end_at' => $this->at('12:15'),
            'price_points' => 200,
            'payment_state' => PaymentState::Unpaid,
            ...$attributes,
        ]);
    }

    private function runAt(string $command, string $time): void
    {
        Carbon::setTestNow($this->at($time));
        $this->artisan($command)->assertExitCode(0);
    }

    public function test_a_room_booking_is_announced_once_when_its_window_opens(): void
    {
        Event::fake([BookingReady::class]);
        $booking = $this->booking();

        $this->runAt('bookings:announce-ready', '09:59');
        Event::assertNotDispatched(BookingReady::class);

        $this->runAt('bookings:announce-ready', '10:00');
        $this->runAt('bookings:announce-ready', '10:01');

        Event::assertDispatchedTimes(BookingReady::class, 1);
        Event::assertDispatched(BookingReady::class, fn (BookingReady $e) => $e->broadcastAs() === 'booking.ready'
            && $e->broadcastWith() === ['booking_id' => $booking->id]
            && $e->memberId === $this->member->id);
        $this->assertTrue($booking->fresh()->ready_announced_at->equalTo($this->at('10:00')));
    }

    public function test_a_coworking_checked_in_or_unconfirmed_booking_is_not_announced(): void
    {
        Event::fake([BookingReady::class]);
        $this->booking(type: SpaceType::CoSpace);
        $this->booking(['checked_in_at' => $this->at('10:15')]);
        $this->booking(['status' => BookingStatus::Pending]);
        $this->booking(['status' => BookingStatus::Cancelled]);

        $this->runAt('bookings:announce-ready', '10:05');

        Event::assertNotDispatched(BookingReady::class);
    }

    public function test_a_checked_in_room_closes_at_its_end(): void
    {
        $booking = $this->booking(['checked_in_at' => $this->at('10:15')]);

        $this->runAt('bookings:close-ended-rooms', '12:14');
        $this->assertNull($booking->fresh()->checked_out_at);

        $this->runAt('bookings:close-ended-rooms', '12:16');

        $booking->refresh();
        $this->assertTrue($booking->checked_out_at->equalTo($this->at('12:15')));
        $this->assertSame(TerminationSource::Auto, $booking->termination_source);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertSame(200, $booking->price_points);
    }

    public function test_an_extended_room_closes_at_its_new_end(): void
    {
        $booking = $this->booking(['checked_in_at' => $this->at('10:15')]);
        Carbon::setTestNow($this->at('11:30'));
        app(BookingExtensionService::class)->extend($booking, 60);

        $this->runAt('bookings:close-ended-rooms', '12:16');
        $this->assertNull($booking->fresh()->checked_out_at);

        $this->runAt('bookings:close-ended-rooms', '13:15');
        $this->assertTrue($booking->fresh()->checked_out_at->equalTo($this->at('13:15')));
    }

    public function test_a_room_closed_at_the_desk_first_is_left_as_the_desk_closed_it(): void
    {
        $booking = $this->booking(['checked_in_at' => $this->at('10:15')]);
        Carbon::setTestNow($this->at('11:30'));
        app(SessionClosureService::class)->closeOut($booking, $this->at('11:30'));

        $this->runAt('bookings:close-ended-rooms', '12:16');

        $booking->refresh();
        $this->assertTrue($booking->checked_out_at->equalTo($this->at('11:30')));
        $this->assertSame(TerminationSource::Reception, $booking->termination_source);
    }

    public function test_a_coworking_booking_is_not_closed_at_its_end(): void
    {
        $booking = $this->booking(['checked_in_at' => $this->at('10:15')], SpaceType::CoSpace);

        $this->runAt('bookings:close-ended-rooms', '12:16');

        $this->assertNull($booking->fresh()->checked_out_at);
    }
}

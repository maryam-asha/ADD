<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booking checked out before checkouts wrote `completed` is still
 * `confirmed`; bookings:close-elapsed never looks at a checked-in booking, so
 * this one-off backfill is what gives it its outcome
 * (docs/decisions/booking-outcomes-and-extension.md).
 */
class BackfillCompletedBookingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_03_100500_backfill_completed_bookings.php');
    }

    public function test_a_booking_checked_out_before_the_change_becomes_completed(): void
    {
        $checkedOut = Booking::factory()->checkedIn()->create(['checked_out_at' => now()]);

        $this->migration()->up();

        $this->assertSame(BookingStatus::Completed, $checkedOut->fresh()->status);
    }

    public function test_bookings_that_are_not_checked_out_are_left_alone(): void
    {
        $running = Booking::factory()->checkedIn()->create();
        $upcoming = Booking::factory()->create();
        $cancelled = Booking::factory()->cancelled()->create();

        $this->migration()->up();

        $this->assertSame(BookingStatus::Confirmed, $running->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $upcoming->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_down_returns_completed_bookings_to_confirmed(): void
    {
        $completed = Booking::factory()->checkedIn()->create([
            'checked_out_at' => now(),
            'status' => BookingStatus::Completed,
        ]);

        $this->migration()->down();

        $this->assertSame(BookingStatus::Confirmed, $completed->fresh()->status);
    }
}

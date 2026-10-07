<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCheckInService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class BookingCheckInServiceTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_scan_check_in_records_the_kiosk(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk] = $this->pairedKiosk($space);
        $booking = Booking::factory()->create(['space_id' => $space->id, 'start_at' => now(), 'end_at' => now()->addHour()]);

        app(BookingCheckInService::class)->checkIn($booking, SessionStartSource::Scan, $kiosk);

        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo(now()));
        $this->assertSame(SessionStartSource::Scan, $booking->checked_in_via);
        $this->assertSame($kiosk->id, $booking->kiosk_id);
    }

    /** Let in early, but the session begins when the booking does. */
    public function test_an_early_check_in_is_recorded_at_the_booking_start(): void
    {
        $space = $this->coworkingSpace();
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addMinutes(10),
            'end_at' => now()->addMinutes(70),
        ]);

        app(BookingCheckInService::class)->checkIn($booking);

        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo($booking->start_at));
    }

    /** More than the window ahead of its start, the booking is refused. */
    public function test_a_check_in_long_before_the_start_is_refused(): void
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'start_at' => now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES + 1),
            'end_at' => now()->addHours(2),
        ]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in long before the start');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_too_early', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertNull($booking->refresh()->checked_in_at);
    }

    /**
     * Late is always fine while the booking runs: a five-hour booking from
     * 13:00, arrived at 14:30, is checked in at 14:30.
     */
    public function test_a_late_check_in_is_recorded_when_it_happens(): void
    {
        $space = $this->coworkingSpace();
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->subMinutes(90),
            'end_at' => now()->subMinutes(90)->addHours(5),
        ]);

        app(BookingCheckInService::class)->checkIn($booking);

        $this->assertTrue($booking->refresh()->checked_in_at->equalTo(now()));
    }

    public function test_a_pending_booking_is_refused_with_the_existing_key(): void
    {
        $booking = Booking::factory()->pending()->create(['space_id' => $this->coworkingSpace()->id]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in a pending booking');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_requires_approval', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_a_booking_held_for_its_payment_cannot_be_checked_in(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::AwaitingPayment,
            'payment_due_at' => now()->addMinutes(10),
            'space_id' => $this->coworkingSpace()->id,
        ]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in a booking still held for its payment');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_awaiting_payment', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertNull($booking->fresh()->checked_in_at);
    }
}

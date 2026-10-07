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
        $booking = Booking::factory()->create(['space_id' => $space->id]);

        app(BookingCheckInService::class)->checkIn($booking, SessionStartSource::Scan, $kiosk);

        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo(now()));
        $this->assertSame(SessionStartSource::Scan, $booking->checked_in_via);
        $this->assertSame($kiosk->id, $booking->kiosk_id);
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
        }
    }
}

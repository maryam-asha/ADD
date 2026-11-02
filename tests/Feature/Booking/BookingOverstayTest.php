<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Pricing\Services\PriceQuoteService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * Staying past a paid booking's end is billed in whole blocks at the
 * booking's rate, instead of running free until closing (product owner,
 * 2026-11-02).
 */
class BookingOverstayTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private const PRICE = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
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

    /** Paid 10:00–12:00 at PRICE points, checked in at its start. */
    private function paidBooking(): Booking
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->coworkingSpace(hourlyRatePoints: 100)->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Paid,
            'price_points' => self::PRICE,
            'start_at' => $this->local('10:00'),
            'end_at' => $this->local('12:00'),
            'checked_in_at' => $this->local('10:00'),
        ]);

        Payment::factory()->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Accepted,
            'settled_at' => $this->local('09:00'),
            'amount_points' => self::PRICE,
            'user_id' => $booking->user_id,
        ]);

        return $booking;
    }

    private function overstay(Booking $booking, string $from, string $to): int
    {
        return app(PriceQuoteService::class)->forSession($booking, $this->local($from), $this->local($to))->finalPoints;
    }

    private function closeAt(Booking $booking, string $time): Booking
    {
        Carbon::setTestNow($this->local($time));
        app(SessionClosureService::class)->closeOut($booking, now());

        return $booking->refresh();
    }

    public function test_a_paid_booking_closed_at_its_end_owes_nothing(): void
    {
        $booking = $this->closeAt($this->paidBooking(), '12:00');

        $this->assertSame(self::PRICE, (int) $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
    }

    public function test_a_paid_booking_closed_before_its_end_owes_nothing(): void
    {
        $booking = $this->closeAt($this->paidBooking(), '11:00');

        $this->assertSame(self::PRICE, (int) $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
    }

    public function test_a_paid_booking_staying_seventy_minutes_past_its_end_owes_seventy_five(): void
    {
        $booking = $this->paidBooking();
        $extra = $this->overstay($booking, '12:00', '13:15');

        $booking = $this->closeAt($booking, '13:10');

        $this->assertGreaterThan(0, $extra);
        $this->assertSame(self::PRICE + $extra, (int) $booking->price_points);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame($extra, (int) $booking->price_points - $booking->pointsPaid());
    }

    /** Rooms, offices and halls keep today's behaviour: only coworking overstay is billed. */
    public function test_a_paid_room_booking_staying_past_its_end_owes_nothing_more(): void
    {
        $booking = $this->paidBooking();
        $booking->forceFill(['space_id' => Space::factory()->room()->create([
            'building_id' => $booking->space->building_id,
            'hourly_rate_points' => 100,
        ])->id])->save();

        $booking = $this->closeAt($booking->refresh(), '13:10');

        $this->assertSame(self::PRICE, (int) $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
    }

    public function test_the_reception_quote_shows_the_overstay(): void
    {
        $booking = $this->paidBooking();
        Carbon::setTestNow($this->local('13:10'));

        $quote = app(SessionClosureService::class)->quote($booking, now());

        $this->assertSame($this->overstay($booking, '12:00', '13:15'), $quote['points']);
        $this->assertFalse($quote['already_paid']);
    }

    public function test_auto_close_at_branch_closing_bills_the_overstay(): void
    {
        $booking = $this->paidBooking();
        $extra = $this->overstay($booking, '12:00', '20:00');

        Carbon::setTestNow($this->local('20:05'));
        $this->artisan('reception:close-overdue-sessions')->assertSuccessful();

        $booking->refresh();
        $this->assertNotNull($booking->checked_out_at);
        $this->assertSame(self::PRICE + $extra, (int) $booking->price_points);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
    }
}

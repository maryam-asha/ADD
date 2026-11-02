<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * An unpaid coworking booking's late arrival is billed from the arrival or
 * from the booking's start, as coworking.late_arrival_billing says; a paid
 * one is never refunded for it (product owner, 2026-11-02).
 */
class LateArrivalBillingTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow($this->local('12:00'));
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

    /** 10:00–12:00, the member came at 10:30. */
    private function lateBooking(PaymentState $state = PaymentState::Unpaid, int $pricePoints = 200): Booking
    {
        return Booking::factory()->create([
            'space_id' => $this->coworkingSpace(hourlyRatePoints: 100)->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => $state,
            'price_points' => $pricePoints,
            'start_at' => $this->local('10:00'),
            'end_at' => $this->local('12:00'),
            'checked_in_at' => $this->local('10:30'),
        ]);
    }

    private function expected(Booking $booking, string $from): int
    {
        return app(PriceQuoteService::class)->forSession($booking, $this->local($from), $this->local('12:00'))->finalPoints;
    }

    public function test_arrival_policy_bills_from_check_in(): void
    {
        $booking = $this->lateBooking();

        app(SessionClosureService::class)->closeOut($booking, now());

        $this->assertSame($this->expected($booking, '10:30'), (int) $booking->refresh()->price_points);
    }

    public function test_booking_start_policy_bills_from_the_booking_start(): void
    {
        app(SettingService::class)->set('coworking.late_arrival_billing', 'booking_start', SettingValueType::String);
        $booking = $this->lateBooking();

        app(SessionClosureService::class)->closeOut($booking, now());

        $this->assertSame($this->expected($booking, '10:00'), (int) $booking->refresh()->price_points);
        $this->assertGreaterThan($this->expected($booking, '10:30'), (int) $booking->price_points);
    }

    public function test_booking_start_policy_shows_in_the_reception_quote(): void
    {
        app(SettingService::class)->set('coworking.late_arrival_billing', 'booking_start', SettingValueType::String);
        $booking = $this->lateBooking();

        $this->assertSame($this->expected($booking, '10:00'), app(SessionClosureService::class)->quote($booking, now())['points']);
    }

    public function test_a_paid_booking_is_unaffected_by_either_policy(): void
    {
        foreach (['arrival', 'booking_start'] as $policy) {
            app(SettingService::class)->set('coworking.late_arrival_billing', $policy, SettingValueType::String);
            $booking = $this->lateBooking(PaymentState::Paid, 200);

            app(SessionClosureService::class)->closeOut($booking, now());

            $booking->refresh();
            $this->assertSame(200, (int) $booking->price_points, $policy);
            $this->assertSame(PaymentState::Paid, $booking->payment_state, $policy);
        }
    }
}

<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A room, office or hall is reserved for its whole booking, so it costs its
 * booked price however long it was used — arriving late or leaving early
 * changes nothing. A coworking booking is still billed for its time.
 */
class RoomBookingClosureTest extends TestCase
{
    use RefreshDatabase;

    private SessionClosureService $closures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus')->utc());
        $this->closures = app(SessionClosureService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function space(SpaceType $type = SpaceType::Room): Space
    {
        $space = Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
    }

    /** @param  array<string, mixed>  $extra */
    private function booking(Space $space, string $checkedIn, array $extra = []): Booking
    {
        return Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('12:00'),
            'checked_in_at' => $this->at($checkedIn),
            'price_points' => 200,
            'chosen_payment_method' => 'cash',
            ...$extra,
        ]);
    }

    public function test_a_room_closed_early_still_costs_its_booked_price(): void
    {
        $booking = $this->booking($this->space(), '10:00');

        $this->closures->closeOut($booking, $this->at('10:30'));

        $booking->refresh();
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
    }

    public function test_a_room_checked_in_late_still_costs_its_booked_price(): void
    {
        $booking = $this->booking($this->space(), '11:00');

        $this->closures->closeOut($booking, $this->at('12:00'));

        $this->assertSame(200, $booking->fresh()->price_points);
    }

    public function test_a_paid_room_closed_early_stays_paid(): void
    {
        $booking = $this->booking($this->space(), '10:00', [
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'paid_at' => $this->at('09:00'),
            'chosen_payment_method' => 'wallet',
        ]);

        $this->closures->closeOut($booking, $this->at('10:30'));

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(200, $booking->price_points);
    }

    public function test_a_room_booked_before_prices_were_kept_costs_its_window(): void
    {
        $booking = $this->booking($this->space(), '10:00', ['price_points' => null]);

        $this->closures->closeOut($booking, $this->at('10:30'));

        $this->assertSame(200, $booking->fresh()->price_points);
    }

    public function test_the_quote_for_a_room_is_its_booked_price(): void
    {
        $booking = $this->booking($this->space(), '10:00');

        $this->assertSame(200, $this->closures->quote($booking, $this->at('10:30'))['points']);
    }

    public function test_a_coworking_booking_is_still_priced_by_its_time(): void
    {
        $booking = $this->booking($this->space(SpaceType::CoSpace), '10:00');

        $this->closures->closeOut($booking, $this->at('10:30'));

        $this->assertSame(50, $booking->fresh()->price_points);
    }
}

<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * An unpaid coworking booking nobody came to is released once its share of
 * time passes, and its member is told (product owner, 2026-11-02).
 */
class ReleaseUnpaidCoworkingNoShowsTest extends TestCase
{
    use InteractsWithCoworking, ReadsNotices, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        $this->space = $this->coworkingSpace();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-08-17 {$time}", 'Asia/Damascus')->utc());
    }

    /** 10:00–14:00 local, confirmed and unpaid: released at 11:00 by the default 25%. */
    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create([
            'space_id' => $this->space->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 400,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 14:00', 'Asia/Damascus')->utc(),
            ...$attributes,
        ]);
    }

    private function release(): void
    {
        $this->artisan('bookings:release-unpaid-no-shows')->assertSuccessful();
    }

    public function test_an_unpaid_booking_is_released_once_a_quarter_has_passed(): void
    {
        $booking = $this->booking();

        $this->at('11:00');
        $this->release();

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $notice = $this->assertNoticed($booking->user_id, 'booking.released_no_show');
        $this->assertSame('11:00', $notice->data['params']['deadline']);
    }

    public function test_an_unpaid_booking_is_kept_before_its_release_instant(): void
    {
        $booking = $this->booking();

        $this->at('10:59');
        $this->release();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertNotNoticed($booking->user_id, 'booking.released_no_show');
    }

    public function test_a_paid_booking_is_never_released(): void
    {
        $booking = $this->booking(['payment_state' => PaymentState::Paid]);

        $this->at('13:00');
        $this->release();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_partly_paid_booking_is_not_released(): void
    {
        $booking = $this->booking();
        Payment::factory()->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Accepted,
            'settled_at' => now(),
            'amount_points' => 100,
            'user_id' => $booking->user_id,
        ]);

        $this->at('11:30');
        $this->release();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_checked_in_booking_is_not_released(): void
    {
        $booking = $this->booking(['checked_in_at' => Carbon::parse('2026-08-17 10:20', 'Asia/Damascus')->utc()]);

        $this->at('11:30');
        $this->release();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_room_booking_is_not_released(): void
    {
        $room = Space::factory()->room()->create(['building_id' => $this->space->building_id]);
        $booking = $this->booking(['space_id' => $room->id]);

        $this->at('11:30');
        $this->release();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_booking_an_open_walk_in_waits_for_is_not_released(): void
    {
        $booking = $this->booking();
        WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $booking->user_id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00', 'Asia/Damascus')->utc(),
            'handover_booking_id' => $booking->id,
        ]);

        $this->at('11:30');
        $this->release();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_the_percent_setting_moves_the_release_instant(): void
    {
        app(SettingService::class)->set('coworking.no_show_release_percent', 50, SettingValueType::Int);
        $booking = $this->booking();

        $this->at('11:00');
        $this->release();
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);

        $this->at('12:00');
        $this->release();
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    public function test_a_released_seat_is_free_for_a_walk_in(): void
    {
        $space = $this->coworkingSpace(capacity: 1);
        $this->space = $space;
        $booking = $this->booking();

        $this->at('11:00');
        $this->release();

        $session = app(WalkInCapacityService::class)->start($space, User::factory()->create())->session;
        $this->assertNotNull($session->id);
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }
}

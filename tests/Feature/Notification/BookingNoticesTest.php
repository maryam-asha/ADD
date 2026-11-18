<?php

namespace Tests\Feature\Notification;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * A booking the desk cancels, or one the member never showed up for, is
 * news to the member; a booking they cancel themselves is not.
 */
class BookingNoticesTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_reception_cancelling_tells_the_member(): void
    {
        $booking = Booking::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['dashboard']);

        $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel")->assertOk();

        $this->assertNoticed($booking->user_id, 'booking.cancelled_no_refund');
    }

    public function test_a_member_cancelling_their_own_booking_hears_nothing(): void
    {
        $booking = Booking::factory()->create();
        $booking->user->assignRole('member');
        Sanctum::actingAs($booking->user, ['member-app']);

        $this->postJson("/api/v1/member/bookings/{$booking->id}/cancel")->assertOk();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_an_elapsed_booking_never_checked_in_is_a_no_show(): void
    {
        $booking = Booking::factory()->create([
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
        ]);

        $this->artisan('bookings:close-elapsed')->assertSuccessful();

        $this->assertSame(BookingStatus::NoShow, $booking->fresh()->status);
        $this->assertNoticed($booking->user_id, 'booking.no_show');
    }
}

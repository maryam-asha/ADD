<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingApprovalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_operations_can_approve_a_pending_booking(): void
    {
        $operator = $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/approve");

        $response->assertOk()->assertExactJson(['message' => 'Booking approved.']);
        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($operator->id, $booking->approved_by);
    }

    public function test_operations_can_reject_a_pending_booking_with_a_reason(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/reject", [
            'rejection_reason' => 'Space unavailable that day.',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Booking rejected.']);
        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
    }

    public function test_rejecting_without_a_reason_fails_validation(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/reject", [])
            ->assertStatus(422);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_approving_an_already_confirmed_booking_fails(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/approve");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking is not awaiting approval.']);
    }

    public function test_a_member_cannot_approve_a_booking(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/approve")->assertForbidden();
    }

    public function test_a_member_cancelling_their_own_pending_booking_releases_the_slot(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        $member = User::factory()->create();
        $start = Carbon::parse('2026-08-17 14:00:00', 'Asia/Damascus');
        $end = Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus');

        $creations = app(BookingCreationService::class);
        $firstBooking = $creations->create($space, $member, $start, $end);
        $this->assertSame(BookingStatus::Pending, $firstBooking->status);

        app(BookingCancellationService::class)->cancel($firstBooking);
        $this->assertSame(BookingStatus::Cancelled, $firstBooking->fresh()->status);

        $secondBooking = $creations->create($space, User::factory()->create(), $start, $end);
        $this->assertSame(BookingStatus::Pending, $secondBooking->status);
    }
}
